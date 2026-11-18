<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.ra_tweaks
 *
 * Walk routes: walk leaders attach a GPX file to any walk, and the walk's popup then offers
 * a link to the route. Walks live in Ramblers Walks Manager, so the files are stored here and
 * linked to a walk by its Walks Manager id.
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

final class WalkRoutes
{
	private const TABLE = '#__ra_tweaks_walk_routes';
	private const FOLDER = 'images/walk-routes';

	private Registry $params;
	private CMSApplicationInterface $app;
	private DatabaseInterface $db;
	private bool $tableChecked = false;

	public function __construct(Registry $params, CMSApplicationInterface $app, DatabaseInterface $db)
	{
		$this->params = $params;
		$this->app = $app;
		$this->db = $db;
	}

	public function enabled(): bool
	{
		return (bool) $this->params->get('walk_routes', 0);
	}

	/** Public link to a stored file, relative to the site root. */
	public static function fileUrl(string $file): string
	{
		return Uri::root(true) . '/' . self::FOLDER . '/' . rawurlencode($file);
	}

	/**
	 * Settings the popup script needs, as an array for json_encode.
	 */
	public function scriptConfig(): array
	{
		$page = trim((string) $this->params->get('routes_page', ''));

		return [
			'lookup' => Uri::root(true) . '/index.php?option=com_ajax&plugin=ra_tweaks&group=system&format=raw&task=routes',
			'folder' => Uri::root(true) . '/' . self::FOLDER . '/',
			'page' => $page,
		];
	}

	/**
	 * com_ajax task=routes: walk id => file name, as JSON, for the popup script.
	 */
	public function lookupJson(): string
	{
		$this->tidyOncePerDay();

		$map = [];

		try {
			$this->ensureTable();
			$query = $this->db->getQuery(true)
				->select([$this->db->quoteName('walk_id'), $this->db->quoteName('file')])
				->from($this->db->quoteName(self::TABLE));
			$this->db->setQuery($query);

			foreach ((array) $this->db->loadObjectList() as $row) {
				$map[(string) $row->walk_id] = (string) $row->file;
			}
		} catch (\Throwable $e) {
			$map = [];
		}

		return json_encode((object) $map, JSON_UNESCAPED_SLASHES) ?: '{}';
	}

	// ---------------------------------------------------------------- permissions

	public function canManage(?User $user = null): bool
	{
		$user = $user ?: $this->app->getIdentity();

		if (!$user || $user->guest) {
			return false;
		}

		if ($user->authorise('core.admin')) {
			return true;
		}

		$group = (int) $this->params->get('routes_group', 0);

		return $group > 0 && \in_array($group, array_map('intval', (array) $user->getAuthorisedGroups()), true);
	}

	// ---------------------------------------------------------------- shortcode pages

	/**
	 * Replace {ra_walk_route_upload} and {ra_walk_route} in article text.
	 */
	public function replaceShortcodes(string $text): string
	{
		if (!$this->enabled() || strpos($text, '{ra_walk_route') === false) {
			return $text;
		}

		if (strpos($text, '{ra_walk_route_upload}') !== false) {
			$text = str_replace('{ra_walk_route_upload}', '<div class="ra-walk-route-page">' . $this->renderUpload() . '</div>', $text);
		}

		if (strpos($text, '{ra_walk_route}') !== false) {
			$text = str_replace('{ra_walk_route}', '<div class="ra-walk-route-page">' . $this->renderRoute() . '</div>', $text);
		}

		return $text;
	}

	private function renderRoute(): string
	{
		$id = preg_replace('/[^0-9]/', '', (string) $this->app->getInput()->getString('walk', ''));

		if ($id === '') {
			return '<p>No walk was chosen. Open a walk from the walks list and choose View route.</p>';
		}

		$row = $this->find($id);

		if ($row === null) {
			return '<p>Sorry, there is no route for that walk. It may have been removed after the walk took place.</p>';
		}

		if (!class_exists('RLeafletGpxMap')) {
			return '<p>The route map needs the Ramblers library, which is not available on this site.</p>';
		}

		$relative = self::FOLDER . '/' . $row->file;
		$html = '';

		if ((string) $row->title !== '') {
			$when = $row->walk_date ? $this->longDate((string) $row->walk_date) : '';
			$html .= '<p class="ra-walk-route-title"><strong>' . $this->e((string) $row->title) . '</strong>'
				. ($when !== '' ? ' &ndash; ' . $this->e($when) : '') . '</p>';
		}

		ob_start();

		try {
			$map = new \RLeafletGpxMap();
			$map->linecolour = '#782327';
			$map->addDownloadLink = 'Public';
			$map->displayPath($relative);
		} catch (\Throwable $e) {
			ob_end_clean();

			return $html . '<p>Sorry, the route could not be shown.</p>';
		}

		return $html . $this->wholeZoomScript() . ob_get_clean() . $this->defaultMapScript() . $this->hideEmptyTimeScript();
	}

	/**
	 * Tidies the figures the library prints above the map and the elevation graph:
	 *  - removes "Actual Time: 0hrs 0mins" (a file with no timestamps);
	 *  - adds feet after the metres for the altitudes and the climb;
	 *  - redraws the graph's height scale in feet (the library only labels it in metres, and its
	 *    "m" label overlaps the top figure), with Feet / Metres buttons to switch.
	 * The library draws the graph once, as an SVG copy in the page, so its scale is relabelled in
	 * place: the plotted shape is the same in either unit.
	 */
	private function hideEmptyTimeScript(): string
	{
		return '<script>' . <<<'JS'
(function(){
	var tries = 0;
	var timer = window.setInterval(function() {
		var page = document.querySelector("[leaflet-browser-print-pages]");

		if (!page || page.innerHTML.indexOf("Distance") === -1) {
			if (++tries > 100) { window.clearInterval(timer); }
			return;
		}

		window.clearInterval(timer);

		var html = page.innerHTML;
		html = html.replace(/<b>Actual Time:<\/b>\s*0hrs 0mins\s*<br\s*\/?>/i, "");
		html = html.replace(/(<b>(?:Min Altitude|Max Altitude|Elevation Gain):<\/b>\s*)(-?\d+) m(?! \/)/g, function(m, a, v) {
			return a + v + " m / " + Math.round(v * 3.28084) + " ft";
		});
		page.innerHTML = html;

		units(page);
	}, 200);

	function units(page) {
		var holder = page.querySelector(".elevation");
		var svg = holder && holder.querySelector("svg");
		var axis = svg && svg.querySelector(".y.axis");
		var ticks = axis ? axis.querySelectorAll(".tick") : [];

		if (!window.d3 || ticks.length < 2) {
			return;
		}

		function ty(g) {
			return parseFloat(/,\s*([-0-9.e]+)\)/.exec(g.getAttribute("transform"))[1]);
		}

		var y1 = ty(ticks[0]), y2 = ty(ticks[1]);
		var v1 = parseFloat(ticks[0].textContent), v2 = parseFloat(ticks[1].textContent);
		var slope = (y2 - y1) / (v2 - v1);
		var offset = y1 - slope * v1;
		var domainPath = axis.querySelector(".domain");
		var height = domainPath ? parseFloat((/V([0-9.]+)/.exec(domainPath.getAttribute("d")) || [0, 85])[1]) : 85;
		var low = (height - offset) / slope;
		var high = (0 - offset) / slope;

		// Room above the scale for the unit label, so it never sits on the top figure.
		var group = svg.querySelector("g");
		var move = /translate\(\s*([-0-9.]+)[ ,]+([-0-9.]+)\s*\)/.exec(group.getAttribute("transform") || "");

		if (move) {
			group.setAttribute("transform", "translate(" + move[1] + "," + (parseFloat(move[2]) + 12) + ")");
			svg.setAttribute("height", parseFloat(svg.getAttribute("height")) + 12);
		}

		function draw(feet) {
			var factor = feet ? 3.28084 : 1;
			var scale = d3.scale.linear().domain([low * factor, high * factor]).range([height, 0]);
			var node = d3.select(axis);

			node.selectAll("*").remove();
			node.call(d3.svg.axis().scale(scale).ticks(3).orient("left"));
			node.append("text").attr("x", -9).attr("y", -9).style("text-anchor", "end").text(feet ? "ft" : "m");
		}

		var bar = document.createElement("div");
		bar.className = "ra-tweaks-elevation-units";
		bar.setAttribute("role", "group");
		bar.setAttribute("aria-label", "Elevation units");
		bar.style.cssText = "margin:0.5em 0 0.25em;display:flex;gap:0.4em;";

		var buttons = [];

		[["Feet", true], ["Metres", false]].forEach(function(item) {
			var button = document.createElement("button");
			button.type = "button";
			button.textContent = item[0];
			button.addEventListener("click", function() { choose(item[1]); });
			bar.appendChild(button);
			buttons.push([button, item[1]]);
		});

		function choose(feet) {
			draw(feet);

			buttons.forEach(function(pair) {
				var on = pair[1] === feet;
				pair[0].setAttribute("aria-pressed", on ? "true" : "false");
				pair[0].style.cssText = "font:inherit;font-size:0.875em;font-weight:600;cursor:pointer;border-radius:999px;padding:0.15em 0.9em;border:1px solid #214d3c;"
					+ (on ? "background:#214d3c;color:#fff;" : "background:#fff;color:#214d3c;");
			});
		}

		holder.parentNode.insertBefore(bar, holder);
		choose(true);
	}
})();
JS . '</script>';
	}

	/**
	 * Keeps the Ordnance Survey raster map sharp. Two things blur it:
	 *  1. The library lets its maps zoom in tenths of a level, and fitting a route to the map
	 *     lands on a fractional zoom such as 13.4, so the browser stretches the tiles. Zoom in
	 *     whole levels instead.
	 *  2. The OS tiles are 256 pixels, which a high-density (retina) screen stretches. Leaflet's
	 *     detectRetina fetches the next, sharper level and draws it at half size, on those
	 *     screens only. Only the OS raster layer is changed.
	 * Both must be in place before the library builds its map, so this goes ahead of the map in
	 * the page.
	 */
	private function wholeZoomScript(): string
	{
		return '<script>(function(){var done=false;function hook(){if(done){return true;}'
			. 'if(!(window.L&&L.Map&&L.Map.addInitHook&&L.TileLayer)){return false;}done=true;'
			. 'L.Map.addInitHook(function(){this.options.zoomSnap=1;});'
			. 'var init=L.TileLayer.prototype.initialize;'
			. 'L.TileLayer.prototype.initialize=function(url,options){'
			. 'if(typeof url==="string"&&url.indexOf("api.os.uk/maps/raster")!==-1){options=L.extend({},options,{detectRetina:true});}'
			. 'return init.call(this,url,options);};return true;}'
			. 'if(!hook()){var n=0,t=window.setInterval(function(){if(hook()||++n>200){window.clearInterval(t);}},10);}})();</script>';
	}

	/**
	 * The library always opens its maps on OpenStreetMap, so after the map is drawn pick the
	 * chosen layer in the map's own layer control, as a visitor would. Does nothing if that
	 * layer is not on offer (the library only adds the Ordnance Survey ones when it has a key).
	 * The Explorer/Landranger map stops at a fixed zoom, so for a very short route the map is
	 * zoomed in too far, the layer control greys that choice out, and the OS vector map is used.
	 */
	private function defaultMapScript(): string
	{
		$layer = trim((string) $this->params->get('routes_default_map', 'OS Explorer/Landranger'));

		if ($layer === '' || $layer === 'Open Street Map') {
			return '';
		}

		$name = json_encode($layer, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

		return '<script>(function(){var want=' . $name . ',tries=0;'
			. 'function find(n){var labels=document.querySelectorAll(".leaflet-control-layers-base label");'
			. 'for(var i=0;i<labels.length;i++){if(labels[i].textContent.trim()===n){return labels[i].querySelector("input");}}return null;}'
			. 'function pick(){var input=find(want);'
			. 'if(input&&!input.disabled){if(!input.checked){input.click();}return;}'
			. 'if(input&&tries>=12){var other=find("Ordnance Survey");if(other&&!other.disabled&&want!=="Ordnance Survey"){other.click();}return;}'
			. 'if(++tries<40){window.setTimeout(pick,250);}}'
			. 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",pick);}else{pick();}})();</script>';
	}

	private function renderUpload(): string
	{
		$user = $this->app->getIdentity();

		if (!$user || $user->guest) {
			return '<p>Please log in to add a route to a walk.</p>';
		}

		if (!$this->canManage($user)) {
			return '<p>Sorry, only walk leaders can add routes to walks.</p>';
		}

		$this->ensureTable();

		if ($this->app->getInput()->getMethod() === 'POST' && $this->app->getInput()->post->getString('ra_route_action', '') !== '') {
			$this->handlePost();
		}

		[$walks, $error] = $this->upcomingWalks();
		$routes = $this->allRoutes();
		$token = Session::getFormToken();
		$maxMb = $this->maxMb();
		$out = '<div class="ra-walk-routes">';

		$out .= '<h3>Add or replace a route</h3>';

		if ($error !== '') {
			$out .= '<p><strong>' . $this->e($error) . '</strong></p>';
		}

		if ($walks) {
			$out .= '<form method="post" enctype="multipart/form-data" action="' . $this->e($this->selfUrl()) . '">'
				. '<p><label for="ra-route-walk">Walk</label><br>'
				. '<select name="walk" id="ra-route-walk" class="form-select" required>'
				. '<option value="">Choose a walk&hellip;</option>';

			foreach ($walks as $walk) {
				$label = $this->shortDate($walk['date']) . ' &ndash; ' . $this->e($walk['title'])
					. (isset($routes[$walk['id']]) ? ' (has a route)' : '');
				$out .= '<option value="' . $this->e($walk['id']) . '">' . $label . '</option>';
			}

			$out .= '</select></p>'
				. '<p><label for="ra-route-file">GPX file (up to ' . $maxMb . ' MB)</label><br>'
				. '<input type="file" name="gpx" id="ra-route-file" class="form-control" accept=".gpx,application/gpx+xml,text/xml" required></p>'
				. '<input type="hidden" name="ra_route_action" value="save">'
				. '<input type="hidden" name="' . $this->e($token) . '" value="1">'
				. '<p><button type="submit" class="btn btn-primary">Attach route</button></p>'
				. '</form>';
		} elseif ($error === '') {
			$out .= '<p>There are no upcoming walks to attach a route to.</p>';
		}

		$out .= '<h3>Routes already attached</h3>';

		if (!$routes) {
			$out .= '<p>None yet.</p>';
		} else {
			$out .= '<table class="table"><thead><tr><th>Walk</th><th>Date</th><th>Route</th><th></th></tr></thead><tbody>';

			foreach ($routes as $row) {
				$view = trim((string) $this->params->get('routes_page', ''));
				$viewLink = $view !== ''
					? '<a href="' . $this->e($this->routeUrl($view, (string) $row->walk_id)) . '">View</a> &middot; '
					: '';
				$out .= '<tr><td>' . $this->e((string) $row->title) . '</td>'
					. '<td>' . $this->e($row->walk_date ? $this->shortDate((string) $row->walk_date) : '') . '</td>'
					. '<td>' . $viewLink . '<a href="' . $this->e(self::fileUrl((string) $row->file)) . '" download>Download</a></td>'
					. '<td><form method="post" action="' . $this->e($this->selfUrl()) . '" onsubmit="return confirm(\'Remove this route?\');">'
					. '<input type="hidden" name="ra_route_action" value="delete">'
					. '<input type="hidden" name="walk" value="' . $this->e((string) $row->walk_id) . '">'
					. '<input type="hidden" name="' . $this->e($token) . '" value="1">'
					. '<button type="submit" class="btn btn-sm btn-outline-danger">Remove</button></form></td></tr>';
			}

			$out .= '</tbody></table>';
		}

		return $out . '</div>';
	}

	// ---------------------------------------------------------------- form handling

	private function handlePost(): void
	{
		if (!Session::checkToken('post')) {
			$this->finish('Sorry, that form has expired. Please try again.', 'error');
		}

		$input = $this->app->getInput();
		$action = $input->post->getString('ra_route_action', '');
		$walkId = preg_replace('/[^0-9]/', '', $input->post->getString('walk', ''));

		if ($walkId === '') {
			$this->finish('Please choose a walk.', 'error');
		}

		if ($action === 'delete') {
			$this->remove($walkId);
			$this->finish('The route has been removed.', 'message');
		}

		if ($action !== 'save') {
			return;
		}

		[$walks] = $this->upcomingWalks();
		$chosen = null;

		foreach ($walks as $walk) {
			if ($walk['id'] === $walkId) {
				$chosen = $walk;
				break;
			}
		}

		if ($chosen === null) {
			$this->finish('That walk is not in the list of upcoming walks.', 'error');
		}

		$upload = $input->files->get('gpx', [], 'array');
		$problem = $this->validateUpload((array) $upload);

		if ($problem !== '') {
			$this->finish($problem, 'error');
		}

		$xml = (string) file_get_contents((string) $upload['tmp_name']);

		if (!$this->store($chosen, $xml)) {
			$this->finish('Sorry, the route could not be saved.', 'error');
		}

		$this->finish('The route has been attached to "' . $chosen['title'] . '".', 'message');
	}

	/** Returns '' when the upload is a usable GPX file, otherwise a message for the leader. */
	private function validateUpload(array $upload): string
	{
		if (empty($upload['tmp_name']) || (int) ($upload['error'] ?? 0) !== UPLOAD_ERR_OK) {
			return 'The file did not upload. Please try again.';
		}

		if (!is_uploaded_file((string) $upload['tmp_name'])) {
			return 'The file did not upload. Please try again.';
		}

		if (strtolower(pathinfo((string) ($upload['name'] ?? ''), PATHINFO_EXTENSION)) !== 'gpx') {
			return 'The file needs to be a .gpx file.';
		}

		if ((int) ($upload['size'] ?? 0) > $this->maxMb() * 1048576) {
			return 'That file is too big. The limit is ' . $this->maxMb() . ' MB.';
		}

		$xml = (string) file_get_contents((string) $upload['tmp_name']);

		return $this->validGpx($xml) ? '' : 'That does not look like a valid GPX file.';
	}

	/** A GPX document with at least two points and no entity tricks. */
	private function validGpx(string $xml): bool
	{
		if ($xml === '' || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
			return false;
		}

		$previous = libxml_use_internal_errors(true);
		$doc = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		if ($doc === false || strtolower($doc->getName()) !== 'gpx') {
			return false;
		}

		$points = $doc->xpath('//*[local-name()="trkpt" or local-name()="rtept"]');

		return \is_array($points) && \count($points) >= 2;
	}

	/**
	 * Many GPX files, including the ones OS Maps exports, carry positions but no heights, so the
	 * route page shows "No elevation data" and an empty profile. When a file has no heights at
	 * all, look them up (Open-Meteo's free elevation service, 90 m terrain model, in batches of
	 * 100 points), smooth them lightly and write them into the stored copy. If the service cannot be reached the
	 * file is stored as it came.
	 */
	public function withElevation(string $xml): string
	{
		$doc = new \DOMDocument();
		$previous = libxml_use_internal_errors(true);
		$loaded = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		if (!$loaded) {
			return $xml;
		}

		$xpath = new \DOMXPath($doc);
		$points = [];

		foreach ($xpath->query('//*[local-name()="trkpt" or local-name()="rtept"]') as $point) {
			if ($xpath->query('*[local-name()="ele"]', $point)->length > 0) {
				return $xml;
			}

			$points[] = $point;
		}

		if ($points === [] || \count($points) > 3000) {
			return $xml;
		}

		$http = HttpFactory::getHttp();
		$heights = [];

		foreach (array_chunk($points, 100) as $chunk) {
			$lat = [];
			$lon = [];

			foreach ($chunk as $point) {
				$lat[] = round((float) $point->getAttribute('lat'), 5);
				$lon[] = round((float) $point->getAttribute('lon'), 5);
			}

			try {
				$response = $http->get(
					'https://api.open-meteo.com/v1/elevation?latitude=' . implode(',', $lat) . '&longitude=' . implode(',', $lon),
					[],
					8
				);
				$data = json_decode((string) $response->getBody(), true);
			} catch (\Throwable $e) {
				return $xml;
			}

			if (!\is_array($data) || !isset($data['elevation']) || \count($data['elevation']) !== \count($chunk)) {
				return $xml;
			}

			foreach ($data['elevation'] as $height) {
				$heights[] = (float) $height;
			}
		}

		// The terrain model is coarse, so raw heights jitter and the climb adds up too high.
		// A five-point average brings the total close to what OS Maps itself reports.
		$count = \count($heights);
		$smooth = [];

		for ($i = 0; $i < $count; $i++) {
			$window = \array_slice($heights, max(0, $i - 2), min($count, $i + 3) - max(0, $i - 2));
			$smooth[$i] = array_sum($window) / \count($window);
		}

		foreach ($points as $i => $point) {
			$ele = $doc->createElementNS($point->namespaceURI, 'ele', (string) round($smooth[$i], 1));
			$point->insertBefore($ele, $point->firstChild);
		}

		$out = $doc->saveXML();

		return \is_string($out) && $out !== '' ? $out : $xml;
	}

	/** @param array{id:string,title:string,date:string,group:string} $walk */
	private function store(array $walk, string $xml): bool
	{
		$dir = JPATH_ROOT . '/' . self::FOLDER;

		if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
			return false;
		}

		if (!is_file($dir . '/index.html')) {
			@file_put_contents($dir . '/index.html', '<!DOCTYPE html><title></title>');
		}

		$file = 'walk-' . $walk['id'] . '-' . bin2hex(random_bytes(4)) . '.gpx';

		if (@file_put_contents($dir . '/' . $file, $this->withElevation($xml)) === false) {
			return false;
		}

		$old = $this->find($walk['id']);
		$user = $this->app->getIdentity();

		$row = (object) [
			'walk_id' => $walk['id'],
			'file' => $file,
			'title' => mb_substr($walk['title'], 0, 250),
			'walk_date' => $walk['date'] !== '' ? $walk['date'] : null,
			'group_code' => mb_substr($walk['group'], 0, 10),
			'user_id' => (int) $user->id,
			'uploaded' => Factory::getDate()->toSql(),
		];

		try {
			if ($old) {
				$this->db->updateObject(self::TABLE, $row, 'walk_id', true);
				$this->deleteFile((string) $old->file);
			} else {
				$this->db->insertObject(self::TABLE, $row);
			}
		} catch (\Throwable $e) {
			@unlink($dir . '/' . $file);

			return false;
		}

		return true;
	}

	private function remove(string $walkId): void
	{
		$row = $this->find($walkId);

		if ($row === null) {
			return;
		}

		$query = $this->db->getQuery(true)
			->delete($this->db->quoteName(self::TABLE))
			->where($this->db->quoteName('walk_id') . ' = :id')
			->bind(':id', $walkId);
		$this->db->setQuery($query)->execute();
		$this->deleteFile((string) $row->file);
	}

	private function deleteFile(string $file): void
	{
		if ($file !== '' && basename($file) === $file) {
			@unlink(JPATH_ROOT . '/' . self::FOLDER . '/' . $file);
		}
	}

	private function finish(string $message, string $type): void
	{
		$this->app->enqueueMessage($message, $type);
		$this->app->redirect($this->selfUrl());
	}

	// ---------------------------------------------------------------- data

	private function ensureTable(): void
	{
		if ($this->tableChecked) {
			return;
		}

		$this->tableChecked = true;
		$this->db->setQuery(
			'CREATE TABLE IF NOT EXISTS ' . $this->db->quoteName(self::TABLE) . ' ('
			. '`walk_id` VARCHAR(20) NOT NULL,'
			. '`file` VARCHAR(100) NOT NULL,'
			. '`title` VARCHAR(255) NOT NULL DEFAULT \'\','
			. '`walk_date` DATE NULL,'
			. '`group_code` VARCHAR(10) NOT NULL DEFAULT \'\','
			. '`user_id` INT NOT NULL DEFAULT 0,'
			. '`uploaded` DATETIME NOT NULL,'
			. 'PRIMARY KEY (`walk_id`)'
			. ') DEFAULT CHARSET=utf8mb4'
		)->execute();
	}

	private function find(string $walkId): ?object
	{
		try {
			$this->ensureTable();
			$query = $this->db->getQuery(true)
				->select('*')
				->from($this->db->quoteName(self::TABLE))
				->where($this->db->quoteName('walk_id') . ' = :id')
				->bind(':id', $walkId);
			$this->db->setQuery($query);
			$row = $this->db->loadObject();
		} catch (\Throwable $e) {
			return null;
		}

		return $row ?: null;
	}

	/** @return array<string,object> keyed by walk id, soonest walk first */
	private function allRoutes(): array
	{
		try {
			$query = $this->db->getQuery(true)
				->select('*')
				->from($this->db->quoteName(self::TABLE))
				->order($this->db->quoteName('walk_date') . ' ASC');
			$this->db->setQuery($query);
			$rows = (array) $this->db->loadObjectList();
		} catch (\Throwable $e) {
			return [];
		}

		$out = [];

		foreach ($rows as $row) {
			$out[(string) $row->walk_id] = $row;
		}

		return $out;
	}

	/**
	 * Remove routes for walks that took place more than routes_keep_days ago. Checked at most
	 * once a day, from the popup lookup.
	 */
	private function tidyOncePerDay(): void
	{
		$marker = JPATH_ROOT . '/' . self::FOLDER . '/.last-tidy';

		if (is_file($marker) && filemtime($marker) > time() - 86400) {
			return;
		}

		if (!is_dir(\dirname($marker))) {
			return;
		}

		@touch($marker);

		try {
			$this->ensureTable();
			$days = max(1, (int) $this->params->get('routes_keep_days', 30));
			$cutoff = Factory::getDate('-' . $days . ' days')->format('Y-m-d');
			$query = $this->db->getQuery(true)
				->select('*')
				->from($this->db->quoteName(self::TABLE))
				->where($this->db->quoteName('walk_date') . ' IS NOT NULL')
				->where($this->db->quoteName('walk_date') . ' < :cutoff')
				->bind(':cutoff', $cutoff);
			$this->db->setQuery($query);

			foreach ((array) $this->db->loadObjectList() as $row) {
				$this->remove((string) $row->walk_id);
			}
		} catch (\Throwable $e) {
			// Tidying is best effort.
		}
	}

	// ---------------------------------------------------------------- Walks Manager feed

	/**
	 * Upcoming walks for the group(s), soonest first.
	 *
	 * @return array{0: array<int,array{id:string,title:string,date:string,group:string}>, 1: string}
	 */
	private function upcomingWalks(): array
	{
		$groups = $this->groupCodes();

		if ($groups === '') {
			return [[], 'The group code has not been set. An administrator needs to set it in the RA Tweaks plugin.'];
		}

		$key = $this->apiKey();

		if ($key === '') {
			return [[], 'The walks feed key could not be found, so the list of walks is unavailable.'];
		}

		$cache = JPATH_CACHE . '/ra_tweaks_walks_' . md5($groups) . '.json';
		$body = null;

		if (is_file($cache) && filemtime($cache) > time() - 600) {
			$body = (string) file_get_contents($cache);
		}

		if ($body === null || $body === '') {
			$weeks = max(1, (int) $this->params->get('routes_weeks', 12));
			$url = $this->feedBase() . 'api-key=' . rawurlencode($key) . '&types=group-walk&groups=' . rawurlencode($groups)
				. '&date=' . Factory::getDate()->format('Y-m-d')
				. '&date_end=' . Factory::getDate('+' . $weeks . ' weeks')->format('Y-m-d')
				. '&limit=300';

			try {
				$response = HttpFactory::getHttp()->get($url, [], 15);
				$body = $response->getStatusCode() === 200 ? (string) $response->getBody() : '';
			} catch (\Throwable $e) {
				$body = '';
			}

			if ($body === '') {
				return [[], 'The list of walks could not be fetched from Walks Manager just now. Please try again in a few minutes.'];
			}

			@file_put_contents($cache, $body);
		}

		$data = json_decode($body, true);
		$walks = [];

		foreach ((array) ($data['data'] ?? []) as $w) {
			$id = trim((string) ($w['id'] ?? ''));
			$title = trim((string) ($w['title'] ?? ''));
			$start = (string) ($w['start_date_time'] ?? '');

			if ($id === '' || $title === '' || $start === '' || strtolower((string) ($w['status'] ?? '')) === 'cancelled') {
				continue;
			}

			$walks[] = [
				'id' => $id,
				'title' => $title,
				'date' => substr($start, 0, 10),
				'group' => (string) ($w['group_code'] ?? ''),
			];
		}

		usort($walks, static fn (array $a, array $b): int => strcmp($a['date'] . $a['title'], $b['date'] . $b['title']));

		return [$walks, ''];
	}

	private function groupCodes(): string
	{
		$codes = strtoupper(preg_replace('/[^A-Za-z0-9,]/', '', (string) $this->params->get('routes_group_codes', '')));

		if ($codes !== '') {
			return $codes;
		}

		// Fall back to the home group set in Ramblers Tools, when that is installed.
		try {
			$query = $this->db->getQuery(true)
				->select($this->db->quoteName('params'))
				->from($this->db->quoteName('#__extensions'))
				->where($this->db->quoteName('element') . ' = ' . $this->db->quote('com_ra_tools'));
			$this->db->setQuery($query);
			$tools = new Registry((string) $this->db->loadResult());

			return strtoupper(preg_replace('/[^A-Za-z0-9,]/', '', (string) $tools->get('default_group', '')));
		} catch (\Throwable $e) {
			return '';
		}
	}

	private function feedBase(): string
	{
		return \defined('WALKMANAGER') ? (string) WALKMANAGER : 'https://walks-manager.ramblers.org.uk/api/volunteers/walksevents?';
	}

	/** The Ramblers library carries the Walks Manager key, so use the same one the walk pages use. */
	private function apiKey(): string
	{
		if (\defined('APIKEY')) {
			return (string) APIKEY;
		}

		$file = JPATH_LIBRARIES . '/ramblers/jsonwalks/wm/feed.php';

		if (is_file($file) && preg_match('/define\(\s*["\']APIKEY["\']\s*,\s*["\']([A-Za-z0-9]+)["\']/', (string) file_get_contents($file), $m)) {
			return $m[1];
		}

		return '';
	}

	// ---------------------------------------------------------------- small helpers

	private function maxMb(): int
	{
		return max(1, min(20, (int) $this->params->get('routes_max_mb', 5)));
	}

	private function selfUrl(): string
	{
		$uri = clone Uri::getInstance();
		$uri->setFragment('');

		return $uri->toString(['path', 'query']);
	}

	private function routeUrl(string $page, string $walkId): string
	{
		return $page . (strpos($page, '?') === false ? '?' : '&') . 'walk=' . rawurlencode($walkId);
	}

	private function shortDate(string $iso): string
	{
		$time = strtotime($iso . ' 12:00:00');

		return $time ? date('D j M', $time) : $iso;
	}

	private function longDate(string $iso): string
	{
		$time = strtotime($iso . ' 12:00:00');

		return $time ? date('l j F Y', $time) : $iso;
	}

	private function e(string $text): string
	{
		return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
	}
}
