<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.ra_tweaks
 *
 * Recorded walk routes: members record a group walk with the Ramblers Tools app and send the
 * track here. Several members may record the same walk; each keeps one recording per walk, and
 * one recording per walk is shown on the website (and in the app's walks feed) as "Route walked".
 * The leader's planned route (WalkRoutes) is never replaced.
 *
 * The app signs in through Ramblers Tools (com_ra_tools AppToken, scope walkroutes.write).
 *
 * Privacy: what's stored and published is the track only. Times, names and everything else in
 * the sent file are dropped, and the ends are cut back to the walk's start and finish, so a
 * recording never shows where someone set out from or went home to. The recorder's name is shown
 * only to walks coordinators.
 */

namespace Ramblerseastcheshire\Plugin\System\RaTweaks\Routes;

\defined('_JEXEC') or die;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;

final class Recordings
{
	public const SHORTCODE = '{ra_walk_recordings}';
	public const SCOPE = 'walkroutes.write';

	private const TABLE = '#__ra_tweaks_walk_recordings';

	/** Most points a recording may have (the app simplifies to about 5 m spacing first). */
	public const MAX_POINTS = 5000;

	/** A recording can be sent up to this many days after the walk. */
	public const DAYS = 7;

	/** The trimmed track must start (and finish) this close to the walk's start (and finish). */
	public const NEAR_METRES = 500;

	/** Points before the track first comes this close to the start, and after it last leaves the finish, are cut. */
	public const TRIM_METRES = 200;

	private Registry $params;
	private CMSApplicationInterface $app;
	private DatabaseInterface $db;
	private WalkRoutes $routes;
	private bool $tableChecked = false;

	public function __construct(Registry $params, CMSApplicationInterface $app, DatabaseInterface $db, WalkRoutes $routes)
	{
		$this->params = $params;
		$this->app = $app;
		$this->db = $db;
		$this->routes = $routes;
	}

	public function enabled(): bool
	{
		return $this->routes->enabled() && (bool) $this->params->get('recordings', 0);
	}

	/** Members of the recording group (Registered unless set otherwise), and Super Users. */
	public function canRecord(User $user): bool
	{
		if ($user->guest || $user->block) {
			return false;
		}

		if ($user->authorise('core.admin')) {
			return true;
		}

		$group = (int) $this->params->get('recording_group', 2);

		return $group > 0 && \in_array($group, array_map('intval', (array) $user->getAuthorisedGroups()), true);
	}

	// ---------------------------------------------------------------- app endpoints

	/**
	 * com_ajax task=recording_allowed: {"allowed": true|false} for the member signed in with the
	 * request's token. The app only offers to record when this is true.
	 */
	public function allowedResponse(): void
	{
		$user = $this->tokenUser();

		$this->json(200, ['allowed' => $this->enabled() && $this->canRecord($user)]);
	}

	/**
	 * com_ajax task=recording (POST, multipart): walk_id, walk_date (YYYY-MM-DD) and gpx.
	 */
	public function uploadResponse(): void
	{
		if ($this->app->getInput()->getMethod() !== 'POST') {
			$this->json(405, ['error' => 'Please send the route with POST.']);
		}

		if (!$this->enabled()) {
			$this->json(404, ['error' => 'This website does not take recorded routes.']);
		}

		$user = $this->tokenUser();

		if (!$this->canRecord($user)) {
			$this->json(403, ['error' => 'not_allowed', 'message' => 'Sorry, your website account cannot send recorded routes.']);
		}

		$input = $this->app->getInput()->post;
		$walkId = (string) preg_replace('/\D/', '', $input->getString('walk_id', ''));
		$walkDate = trim($input->getString('walk_date', ''));

		if ($walkId === '') {
			$this->json(422, ['error' => 'The walk was not given.']);
		}

		[$walk, $feedError] = $this->recentWalk($walkId);

		if ($feedError !== '') {
			$this->json(503, ['error' => $feedError]);
		}

		if ($walk === null) {
			$this->json(404, ['error' => 'That walk could not be found.']);
		}

		$today = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/London')))->format('Y-m-d');
		$oldest = (new \DateTimeImmutable('today -' . self::DAYS . ' days', new \DateTimeZone('Europe/London')))->format('Y-m-d');

		if ($walk['date'] > $today) {
			$this->json(422, ['error' => 'That walk has not happened yet.']);
		}

		if ($walk['date'] < $oldest) {
			$this->json(409, ['error' => 'Routes can only be sent up to ' . self::DAYS . ' days after the walk.']);
		}

		if ($walkDate !== '' && $walkDate !== $walk['date']) {
			$this->json(422, ['error' => 'This recording was not made on the day of the walk.']);
		}

		$upload = (array) $this->app->getInput()->files->get('gpx', [], 'array');
		$problem = $this->routes->checkUpload($upload);

		if ($problem !== '') {
			$this->json(422, ['error' => $problem]);
		}

		[$points, $problem] = self::points((string) file_get_contents((string) $upload['tmp_name']));

		if ($problem === '') {
			[$points, $problem] = self::trim($points, $walk['starts'], $walk['finishes']);
		}

		if ($problem !== '') {
			$this->json(422, ['error' => $problem]);
		}

		$row = $this->store($walk, $user, $points);

		if ($row === null) {
			$this->json(500, ['error' => 'Sorry, the route could not be saved. Please try again later.']);
		}

		$shown = (int) $row->shown === 1;

		$this->json(200, [
			'message' => $shown
				? 'Thanks, your route was added. It is the one shown for this walk.'
				: 'Thanks, your route was added.',
			'shown' => $shown,
			'route' => array_filter([
				'kind' => 'walked',
				'gpx_url' => rtrim(Uri::root(), '/') . '/' . WalkRoutes::FOLDER . '/' . rawurlencode((string) $row->file),
				'page_url' => $shown ? $this->pageUrl($walkId, true) : null,
			], static fn ($v) => $v !== null),
		]);
	}

	/** The member signed in with the request's token; answers 401/403 and stops when there is none. */
	private function tokenUser(): User
	{
		$class = '\\Ramblers\\Component\\Ra_tools\\Site\\Helpers\\AppToken';

		if (!class_exists($class)) {
			$this->json(503, ['error' => 'App sign-in needs Ramblers Tools 4.5.0 or later on this website.']);
		}

		$error = null;
		$user = $class::user(self::SCOPE, $error);

		if ($user === null) {
			$this->json($error === 'insufficient_scope' ? 403 : 401, ['error' => (string) $error]);
		}

		return $user;
	}

	/** @return never */
	private function json(int $status, array $body): void
	{
		$this->app->setHeader('status', $status, true);
		$this->app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
		$this->app->setHeader('Cache-Control', 'no-store', true);
		$this->app->sendHeaders();
		echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$this->app->close();
	}

	// ---------------------------------------------------------------- the track

	/**
	 * The track's points as [lat, lon, ele|null], from its track points (or route points when it
	 * has none). Everything else in the file is dropped.
	 *
	 * @return array{0: array<int,array{0:float,1:float,2:?float}>, 1: string}
	 */
	public static function points(string $xml): array
	{
		$previous = libxml_use_internal_errors(true);
		$doc = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		if ($doc === false) {
			return [[], 'That does not look like a valid GPX file.'];
		}

		$nodes = $doc->xpath('//*[local-name()="trkpt"]') ?: $doc->xpath('//*[local-name()="rtept"]') ?: [];

		if (\count($nodes) > self::MAX_POINTS) {
			return [[], 'The route has too many points (the limit is ' . number_format(self::MAX_POINTS) . ').'];
		}

		$points = [];

		foreach ($nodes as $node) {
			$lat = (string) $node['lat'];
			$lon = (string) $node['lon'];

			if (!is_numeric($lat) || !is_numeric($lon)) {
				continue;
			}

			$lat = (float) $lat;
			$lon = (float) $lon;

			// In or near Great Britain.
			if ($lat < 49.8 || $lat > 60.95 || $lon < -8.7 || $lon > 1.8) {
				return [[], 'The route is not in Great Britain.'];
			}

			$ele = null;

			foreach ($node->children() as $child) {
				if (strtolower($child->getName()) === 'ele' && is_numeric(trim((string) $child))) {
					$ele = (float) trim((string) $child);
				}
			}

			$points[] = [$lat, $lon, $ele];
		}

		return \count($points) < 2 ? [[], 'The route needs at least two points.'] : [$points, ''];
	}

	/**
	 * Cuts the track back to the walk: from the first point within TRIM_METRES of the start (or
	 * meeting point) to the last point within TRIM_METRES of the finish. A track that never comes
	 * that close is kept from its first point (or to its last) only if that point is within
	 * NEAR_METRES; otherwise it's refused.
	 *
	 * @param array<int,array{0:float,1:float}> $starts   The walk's start and meeting points
	 * @param array<int,array{0:float,1:float}> $finishes The walk's finish (its start, for a circular walk)
	 * @return array{0: array, 1: string}
	 */
	public static function trim(array $points, array $starts, array $finishes): array
	{
		if ($starts === []) {
			return [[], 'The walk has no start point in Walks Manager, so the route cannot be checked.'];
		}

		$near = static function (array $point, array $places): float {
			$best = INF;

			foreach ($places as $place) {
				$best = min($best, self::metres($point[0], $point[1], $place[0], $place[1]));
			}

			return $best;
		};

		$count = \count($points);
		$first = null;

		for ($i = 0; $i < $count; $i++) {
			if ($near($points[$i], $starts) <= self::TRIM_METRES) {
				$first = $i;
				break;
			}
		}

		if ($first === null) {
			if ($near($points[0], $starts) > self::NEAR_METRES) {
				return [[], 'The route does not start near the walk\'s start.'];
			}

			$first = 0;
		}

		$finishes = $finishes ?: $starts;
		$last = null;

		for ($i = $count - 1; $i > $first; $i--) {
			if ($near($points[$i], $finishes) <= self::TRIM_METRES) {
				$last = $i;
				break;
			}
		}

		if ($last === null) {
			if ($near($points[$count - 1], $finishes) > self::NEAR_METRES) {
				return [[], 'The route does not finish near the walk\'s finish.'];
			}

			$last = $count - 1;
		}

		$kept = \array_slice($points, $first, $last - $first + 1);

		return \count($kept) < 2 ? [[], 'The route is too short.'] : [$kept, ''];
	}

	/** Distance in metres between two points (haversine). */
	public static function metres(float $lat1, float $lon1, float $lat2, float $lon2): float
	{
		$r = 6371000.0;
		$dLat = deg2rad($lat2 - $lat1);
		$dLon = deg2rad($lon2 - $lon1);
		$a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

		return 2 * $r * asin(min(1.0, sqrt($a)));
	}

	public static function length(array $points): int
	{
		$total = 0.0;

		for ($i = 1, $n = \count($points); $i < $n; $i++) {
			$total += self::metres($points[$i - 1][0], $points[$i - 1][1], $points[$i][0], $points[$i][1]);
		}

		return (int) round($total);
	}

	/** A clean GPX file: the walk's title and the points (lat, lon, ele) only. */
	public static function gpx(string $title, array $points): string
	{
		$ns = 'http://www.topografix.com/GPX/1/1';
		$doc = new \DOMDocument('1.0', 'UTF-8');
		$gpx = $doc->createElementNS($ns, 'gpx');
		$gpx->setAttribute('version', '1.1');
		$gpx->setAttribute('creator', 'RA Tweaks (recorded route)');
		$doc->appendChild($gpx);
		$trk = $doc->createElementNS($ns, 'trk');
		$gpx->appendChild($trk);
		$name = $doc->createElementNS($ns, 'name');
		$name->appendChild($doc->createTextNode($title));
		$trk->appendChild($name);
		$seg = $doc->createElementNS($ns, 'trkseg');
		$trk->appendChild($seg);

		foreach ($points as $p) {
			$pt = $doc->createElementNS($ns, 'trkpt');
			$pt->setAttribute('lat', number_format($p[0], 6, '.', ''));
			$pt->setAttribute('lon', number_format($p[1], 6, '.', ''));

			if ($p[2] !== null) {
				$pt->appendChild($doc->createElementNS($ns, 'ele', (string) round($p[2], 1)));
			}

			$seg->appendChild($pt);
		}

		return (string) $doc->saveXML();
	}

	// ---------------------------------------------------------------- the walk

	/**
	 * A walk from the last month of the group's walks (Walks Manager), with its date and the
	 * places a recording is checked against.
	 *
	 * @return array{0: ?array{id:string,title:string,date:string,group:string,starts:array,finishes:array}, 1: string}
	 */
	private function recentWalk(string $walkId): array
	{
		$groups = $this->routes->groupCodes();
		$key = $this->routes->apiKey();

		if ($groups === '' || $key === '') {
			return [null, 'The walks list is not set up on this website.'];
		}

		$tz = new \DateTimeZone('Europe/London');
		$from = (new \DateTimeImmutable('today -30 days', $tz))->format('Y-m-d');
		$to = (new \DateTimeImmutable('today', $tz))->format('Y-m-d');
		$cache = JPATH_CACHE . '/ra_tweaks_recent_' . md5($groups . $to) . '.json';
		$body = is_file($cache) && filemtime($cache) > time() - 600 ? (string) file_get_contents($cache) : '';

		if ($body === '') {
			$url = $this->routes->feedBase() . 'api-key=' . rawurlencode($key) . '&types=group-walk&groups=' . rawurlencode($groups)
				. '&date=' . $from . '&date_end=' . $to . '&limit=300';

			try {
				$response = HttpFactory::getHttp()->get($url, [], 15);
				$body = $response->getStatusCode() === 200 ? (string) $response->getBody() : '';
			} catch (\Throwable $e) {
				$body = '';
			}

			if ($body === '') {
				return [null, 'Walks Manager could not be reached just now. Please try again later.'];
			}

			@file_put_contents($cache, $body);
		}

		foreach ((array) (json_decode($body, true)['data'] ?? []) as $w) {
			if (trim((string) ($w['id'] ?? '')) !== $walkId) {
				continue;
			}

			$place = static function ($loc): ?array {
				return \is_array($loc) && is_numeric($loc['latitude'] ?? null) && is_numeric($loc['longitude'] ?? null)
					? [(float) $loc['latitude'], (float) $loc['longitude']]
					: null;
			};
			$starts = array_values(array_filter([$place($w['start_location'] ?? null), $place($w['meeting_location'] ?? null)]));
			$end = $place($w['end_location'] ?? null);

			return [[
				'id' => $walkId,
				'title' => trim((string) ($w['title'] ?? '')),
				'date' => substr((string) ($w['start_date_time'] ?? ''), 0, 10),
				'group' => (string) ($w['group_code'] ?? ''),
				'starts' => $starts,
				'finishes' => $end ? [$end] : $starts,
			], ''];
		}

		return [null, ''];
	}

	// ---------------------------------------------------------------- data

	/** Saves the member's recording of the walk (replacing their earlier one) and returns its row. */
	private function store(array $walk, User $user, array $points): ?object
	{
		$dir = JPATH_ROOT . '/' . WalkRoutes::FOLDER;

		if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
			return null;
		}

		$file = 'walked-' . $walk['id'] . '-' . bin2hex(random_bytes(4)) . '.gpx';
		$xml = $this->routes->withElevation(self::gpx($walk['title'], $points));

		if (@file_put_contents($dir . '/' . $file, $xml) === false) {
			return null;
		}

		$now = Factory::getDate()->toSql();

		try {
			$this->ensureTable();
			$old = $this->mine($walk['id'], (int) $user->id);
			$row = (object) [
				'walk_id' => $walk['id'],
				'user_id' => (int) $user->id,
				'file' => $file,
				'title' => mb_substr($walk['title'], 0, 250),
				'walk_date' => $walk['date'],
				'group_code' => mb_substr($walk['group'], 0, 10),
				'points' => \count($points),
				'metres' => self::length($points),
				'leader' => $this->routes->canManage($user) ? 1 : 0,
				'updated' => $now,
			];

			if ($old) {
				$row->id = (int) $old->id;
				$this->db->updateObject(self::TABLE, $row, 'id');
				$this->routes->deleteFile((string) $old->file);
			} else {
				$row->uploaded = $now;
				$row->pinned = 0;
				$row->shown = 0;
				$this->db->insertObject(self::TABLE, $row, 'id');
			}

			$this->chooseShown($walk['id']);

			return $this->mine($walk['id'], (int) $user->id);
		} catch (\Throwable $e) {
			@unlink($dir . '/' . $file);

			return null;
		}
	}

	/**
	 * Marks the recording the website shows for a walk: the one a coordinator picked; otherwise
	 * the first sent by someone in the walk leaders group; otherwise the longest.
	 */
	private function chooseShown(string $walkId): void
	{
		$rows = $this->forWalk($walkId);

		if ($rows === []) {
			return;
		}

		$pick = null;

		foreach ($rows as $row) {
			if ((int) $row->pinned === 1) {
				$pick = $row;
				break;
			}
		}

		if ($pick === null) {
			$leaders = array_filter($rows, static fn ($r): bool => (int) $r->leader === 1);
			usort($leaders, static fn ($a, $b): int => strcmp((string) $a->uploaded, (string) $b->uploaded));
			$pick = $leaders[0] ?? null;
		}

		if ($pick === null) {
			usort($rows, static fn ($a, $b): int => (int) $b->metres <=> (int) $a->metres);
			$pick = $rows[0];
		}

		$this->db->setQuery(
			'UPDATE ' . $this->db->quoteName(self::TABLE) . ' SET `shown` = (`id` = ' . (int) $pick->id . ')'
			. ' WHERE `walk_id` = ' . $this->db->quote($walkId)
		)->execute();
	}

	public function ensureTable(): void
	{
		if ($this->tableChecked) {
			return;
		}

		$this->tableChecked = true;
		$this->db->setQuery(
			'CREATE TABLE IF NOT EXISTS ' . $this->db->quoteName(self::TABLE) . ' ('
			. '`id` INT NOT NULL AUTO_INCREMENT,'
			. '`walk_id` VARCHAR(20) NOT NULL,'
			. '`user_id` INT NOT NULL,'
			. '`file` VARCHAR(100) NOT NULL,'
			. '`title` VARCHAR(255) NOT NULL DEFAULT \'\','
			. '`walk_date` DATE NULL,'
			. '`group_code` VARCHAR(10) NOT NULL DEFAULT \'\','
			. '`points` INT NOT NULL DEFAULT 0,'
			. '`metres` INT NOT NULL DEFAULT 0,'
			. '`leader` TINYINT NOT NULL DEFAULT 0,'
			. '`pinned` TINYINT NOT NULL DEFAULT 0,'
			. '`shown` TINYINT NOT NULL DEFAULT 0,'
			. '`uploaded` DATETIME NOT NULL,'
			. '`updated` DATETIME NOT NULL,'
			. 'PRIMARY KEY (`id`),'
			. 'UNIQUE KEY `walk_user` (`walk_id`, `user_id`),'
			. 'KEY `walk_shown` (`walk_id`, `shown`)'
			. ') DEFAULT CHARSET=utf8mb4'
		)->execute();
	}

	private function mine(string $walkId, int $userId): ?object
	{
		$query = $this->db->getQuery(true)
			->select('*')
			->from($this->db->quoteName(self::TABLE))
			->where($this->db->quoteName('walk_id') . ' = :walk')
			->where($this->db->quoteName('user_id') . ' = :user')
			->bind(':walk', $walkId)
			->bind(':user', $userId, \Joomla\Database\ParameterType::INTEGER);

		return $this->db->setQuery($query)->loadObject() ?: null;
	}

	/** @return array<int,object> */
	private function forWalk(string $walkId): array
	{
		$query = $this->db->getQuery(true)
			->select('*')
			->from($this->db->quoteName(self::TABLE))
			->where($this->db->quoteName('walk_id') . ' = :walk')
			->bind(':walk', $walkId);

		return (array) $this->db->setQuery($query)->loadObjectList();
	}

	/** The recording shown for a walk, or null. */
	public function shownFor(string $walkId): ?object
	{
		if (!$this->enabled()) {
			return null;
		}

		try {
			$this->ensureTable();
			$query = $this->db->getQuery(true)
				->select('*')
				->from($this->db->quoteName(self::TABLE))
				->where($this->db->quoteName('walk_id') . ' = :walk')
				->where($this->db->quoteName('shown') . ' = 1')
				->bind(':walk', $walkId);

			return $this->db->setQuery($query)->loadObject() ?: null;
		} catch (\Throwable $e) {
			return null;
		}
	}

	/** @return array<string,string> walk id => file of the shown recording */
	public function shownFiles(): array
	{
		if (!$this->enabled()) {
			return [];
		}

		try {
			$this->ensureTable();
			$query = $this->db->getQuery(true)
				->select($this->db->quoteName(['walk_id', 'file']))
				->from($this->db->quoteName(self::TABLE))
				->where($this->db->quoteName('shown') . ' = 1');
			$out = [];

			foreach ((array) $this->db->setQuery($query)->loadObjectList() as $row) {
				$out[(string) $row->walk_id] = (string) $row->file;
			}

			return $out;
		} catch (\Throwable $e) {
			return [];
		}
	}

	private function remove(object $row): void
	{
		$this->db->setQuery('DELETE FROM ' . $this->db->quoteName(self::TABLE) . ' WHERE `id` = ' . (int) $row->id)->execute();
		$this->routes->deleteFile((string) $row->file);
		$this->chooseShown((string) $row->walk_id);
	}

	private function pageUrl(string $walkId, bool $absolute): ?string
	{
		$page = trim((string) $this->params->get('routes_page', ''));

		if ($page === '') {
			return null;
		}

		if ($absolute && !preg_match('#^https?://#i', $page)) {
			$page = rtrim(Uri::root(), '/') . '/' . ltrim($page, '/');
		}

		return $page . (strpos($page, '?') === false ? '?' : '&') . 'walk=' . rawurlencode($walkId) . '&route=walked';
	}

	// ---------------------------------------------------------------- the website page

	/**
	 * {ra_walk_recordings}: members see their own recordings and can delete them; walks
	 * coordinators (the walk leaders group) see every recording, who sent it, and can choose the
	 * one shown for a walk or delete any.
	 */
	public function replaceShortcode(string $text): string
	{
		if (strpos($text, self::SHORTCODE) === false) {
			return $text;
		}

		return str_replace(self::SHORTCODE, '<div class="ra-walk-recordings">' . $this->render() . '</div>', $text);
	}

	private function render(): string
	{
		if (!$this->enabled()) {
			return '<p>Recorded routes are not turned on.</p>';
		}

		$user = $this->app->getIdentity();

		if (!$user || $user->guest) {
			return '<p>Please log in to see your recorded routes.</p>';
		}

		$manager = $this->routes->canManage($user);

		try {
			$this->ensureTable();
		} catch (\Throwable $e) {
			return '<p>Sorry, recorded routes are not available just now.</p>';
		}

		if ($this->app->getInput()->getMethod() === 'POST' && $this->app->getInput()->post->getString('ra_recording', '') !== '') {
			$this->handlePost($user, $manager);
		}

		$query = $this->db->getQuery(true)
			->select('r.*, u.name AS recorder')
			->from($this->db->quoteName(self::TABLE, 'r'))
			->join('LEFT', $this->db->quoteName('#__users', 'u') . ' ON u.id = r.user_id')
			->order('r.walk_date DESC, r.walk_id, r.shown DESC, r.uploaded ASC');

		if (!$manager) {
			$query->where('r.user_id = ' . (int) $user->id);
		}

		$rows = (array) $this->db->setQuery($query)->loadObjectList();
		$token = Session::getFormToken();
		$self = $this->selfUrl();
		$out = $manager
			? '<p>Routes members recorded with the app. The one marked <strong>Shown</strong> is on the website as the route walked, without the name of who recorded it.</p>'
			: '<p>Routes you recorded with the app. The one marked <strong>Shown</strong> is on the website as the route walked, without your name. You can delete yours at any time.</p>';

		if ($rows === []) {
			return $out . '<p>None yet.</p>';
		}

		$out .= '<table class="table"><thead><tr><th>Walk</th><th>Date</th>' . ($manager ? '<th>Recorded by</th>' : '')
			. '<th>Length</th><th>Route</th><th></th></tr></thead><tbody>';

		foreach ($rows as $row) {
			$file = WalkRoutes::fileUrl((string) $row->file);
			$view = (int) $row->shown === 1 ? $this->pageUrl((string) $row->walk_id, false) : null;
			$form = static fn (string $action, string $label, string $class, string $confirm = ''): string => '<form method="post" action="' . htmlspecialchars($self, ENT_QUOTES, 'UTF-8') . '" style="display:inline"'
				. ($confirm !== '' ? ' onsubmit="return confirm(\'' . $confirm . '\');"' : '') . '>'
				. '<input type="hidden" name="ra_recording" value="' . $action . '">'
				. '<input type="hidden" name="ra_recording_id" value="' . (int) $row->id . '">'
				. '<input type="hidden" name="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '" value="1">'
				. '<button type="submit" class="btn btn-sm ' . $class . '">' . $label . '</button></form> ';

			$out .= '<tr><td>' . $this->e((string) $row->title) . ((int) $row->shown === 1 ? ' <strong>(Shown)</strong>' : '') . '</td>'
				. '<td>' . $this->e($row->walk_date ? date('D j M Y', (int) strtotime($row->walk_date . ' 12:00')) : '') . '</td>'
				. ($manager ? '<td>' . $this->e((string) ($row->recorder ?? '')) . '</td>' : '')
				. '<td>' . number_format(((int) $row->metres) / 1609.344, 1) . ' miles</td>'
				. '<td>' . ($view ? '<a href="' . $this->e($view) . '">View</a> &middot; ' : '')
				. '<a href="' . $this->e($file) . '" download>Download</a></td><td>'
				. ($manager && (int) $row->shown !== 1 ? $form('show', 'Show this one', 'btn-outline-primary') : '')
				. ($manager || (int) $row->user_id === (int) $user->id ? $form('delete', 'Delete', 'btn-outline-danger', 'Delete this recorded route?') : '')
				. '</td></tr>';
		}

		return $out . '</tbody></table>';
	}

	private function handlePost(User $user, bool $manager): void
	{
		$back = $this->selfUrl();

		if (!Session::checkToken('post')) {
			$this->app->enqueueMessage('Sorry, that form has expired. Please try again.', 'error');
			$this->app->redirect($back);
		}

		$id = $this->app->getInput()->post->getInt('ra_recording_id', 0);
		$row = $this->db->setQuery('SELECT * FROM ' . $this->db->quoteName(self::TABLE) . ' WHERE `id` = ' . $id)->loadObject();
		$action = $this->app->getInput()->post->getString('ra_recording', '');

		if (!$row || (!$manager && (int) $row->user_id !== (int) $user->id) || ($action === 'show' && !$manager)) {
			$this->app->enqueueMessage('Sorry, you cannot change that recording.', 'error');
			$this->app->redirect($back);
		}

		if ($action === 'delete') {
			$this->remove($row);
			$this->app->enqueueMessage('The recorded route has been deleted.', 'message');
		} elseif ($action === 'show') {
			$this->db->setQuery(
				'UPDATE ' . $this->db->quoteName(self::TABLE) . ' SET `pinned` = (`id` = ' . (int) $row->id . ')'
				. ' WHERE `walk_id` = ' . $this->db->quote((string) $row->walk_id)
			)->execute();
			$this->chooseShown((string) $row->walk_id);
			$this->app->enqueueMessage('That recording is now the one shown for the walk.', 'message');
		}

		$this->app->redirect($back);
	}

	private function selfUrl(): string
	{
		$uri = clone Uri::getInstance();
		$uri->setFragment('');

		return $uri->toString(['path', 'query']);
	}

	private function e(string $text): string
	{
		return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
	}
}
