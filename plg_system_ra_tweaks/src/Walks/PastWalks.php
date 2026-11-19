<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.ra_tweaks
 *
 * Past walks: {ra_past_walks} in an article shows the group's walks that have already
 * happened, newest first, drawn by the Ramblers library the same way as the walks programme
 * (list, calendar, map and the walk popup).
 *
 * The walks come from Walks Manager, which still returns past walks. Each year's walks are
 * kept in a table here, so the page does not depend on Walks Manager keeping them for ever:
 * the current year is fetched again once a day, and a past year is fetched one last time
 * after it ends and then left alone. Before a walk is kept, the leader's phone number and
 * contact form are removed (only the name stays) and cancelled walks are left out.
 */

namespace Ramblerseastcheshire\Plugin\System\RaTweaks\Walks;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;
use Ramblerseastcheshire\Plugin\System\RaTweaks\Routes\WalkRoutes;

final class PastWalks
{
	public const SHORTCODE = '{ra_past_walks}';
	private const TABLE = '#__ra_tweaks_past_walks';

	/** A past year is fetched once more this many days after it ends, then kept as it is. */
	private const SETTLE_DAYS = 30;

	/** Walks Manager returns at most this many walks per request. */
	private const FEED_LIMIT = 1000;

	/**
	 * The walks shown on this page (library walk objects, newest first), for the template,
	 * which draws its own rows for them. Null when the page has no past walks list.
	 *
	 * @var array<int,object>|null
	 */
	public static ?array $shown = null;

	private Registry $params;
	private DatabaseInterface $db;
	private WalkRoutes $routes;

	public function __construct(Registry $params, DatabaseInterface $db, WalkRoutes $routes)
	{
		$this->params = $params;
		$this->db = $db;
		$this->routes = $routes;
	}

	public function replaceShortcode(string $text): string
	{
		if (strpos($text, self::SHORTCODE) === false) {
			return $text;
		}

		$pos = strpos($text, self::SHORTCODE);
		$html = '<div class="ra-past-walks">' . $this->render() . '</div>';

		// Only the first one is drawn: the library supports one walks display per page.
		return str_replace(self::SHORTCODE, '', substr_replace($text, $html, $pos, \strlen(self::SHORTCODE)));
	}

	private function render(): string
	{
		if (!class_exists('RJsonwalksSourcewalksmanager') || !class_exists('RJsonwalksStdDisplay')) {
			return $this->message('The Ramblers library is not installed, so past walks cannot be shown.');
		}

		$groups = $this->routes->groupCodes();

		if ($groups === '') {
			return $this->message('The group code has not been set. An administrator needs to set it in the RA Tweaks plugin.');
		}

		$items = $this->items($groups);

		if (!$items) {
			return $this->message('No past walks are available just now. Please try again later.');
		}

		$this->handleUpload($items);

		$source = new \RJsonwalksSourcewalksmanager();
		$walks = new class extends \RJsonwalksWalks {
			// The library sorts walks soonest first and then reads them with allWalks().
			public function allWalks()
			{
				return array_reverse(parent::allWalks());
			}
		};

		foreach ($items as $item) {
			$walk = new \RJsonwalksWalk();

			try {
				if ($source->convertToInternalFormat($walk, $item)) {
					$walks->addWalk($walk);
				}
			} catch (\Throwable $e) {
				// One bad walk should not stop the rest.
			}
		}

		$walks->sort(\RJsonwalksWalk::SORT_DATE, \RJsonwalksWalk::SORT_TIME, \RJsonwalksWalk::SORT_DISTANCE);
		self::$shown = $walks->allWalks();

		ob_start();

		try {
			// As RJsonwalksFeed::display() does: the popup and other library parts need it.
			\RLoad::addStyleSheet('media/lib_ramblers/css/ramblerslibrary.css');
			(new \RJsonwalksStdDisplay())->DisplayWalks($walks);
		} catch (\Throwable $e) {
			ob_end_clean();

			return $this->message('Past walks could not be shown just now.');
		}

		return $this->searchBox() . (string) ob_get_clean() . $this->keepPastScript() . $this->gpxButtons($items);
	}

	/**
	 * Walk leaders (the Walk Routes leaders group) and Super Users get an "Add GPX" button
	 * beside each walk ("Replace GPX" when it has a route), which opens a small form for that
	 * walk. Nothing of this is sent to anyone else.
	 *
	 * @param array<int,object> $items
	 */
	private function gpxButtons(array $items): string
	{
		if (!$this->routes->enabled() || !$this->routes->canManage()) {
			return '';
		}

		$has = (array) json_decode($this->routes->lookupJson(), true);
		$walks = [];

		foreach ($items as $item) {
			$id = (string) ($item->id ?? '');

			if ($id !== '') {
				$walks[$id] = [$this->walkLabel($item), isset($has[$id]) ? 1 : 0];
			}
		}

		$json = static fn ($v): string => (string) json_encode($v, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
		$e = static fn (string $t): string => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
		$maxMb = max(1, min(20, (int) $this->params->get('routes_max_mb', 5)));

		return '<style>'
			. '.ra-past-walks .ecr-walkrow.ra-gpx-row{display:flex;align-items:center;gap:12px}'
			. '.ra-past-walks .ra-gpx-row>.ecr-walk{flex:1 1 auto;min-width:0}'
			. '.ra-past-walks .ra-gpx-btn{flex:0 0 auto;min-width:8.5rem;white-space:nowrap}'
			. '@media (max-width:599px){.ra-past-walks .ecr-walkrow.ra-gpx-row{flex-wrap:wrap;justify-content:flex-end;gap:6px}'
			. '.ra-past-walks .ra-gpx-row>.ecr-walk{flex-basis:100%}}'
			. '.ra-gpx-dialog{max-width:30rem;width:calc(100% - 32px);border:0;border-radius:8px;padding:20px;box-shadow:0 10px 40px rgba(0,0,0,.3)}'
			. '.ra-gpx-dialog::backdrop{background:rgba(0,0,0,.45)}'
			. '.ra-gpx-dialog h2{font-size:1.25rem;margin:0 0 .25rem}'
			. '.ra-gpx-dialog .ra-gpx-walk{font-weight:600;margin:0 0 1rem}'
			. '.ra-gpx-dialog .ra-gpx-actions{display:flex;gap:8px;justify-content:flex-end;margin-top:1rem}'
			. '</style>'
			. '<dialog class="ra-gpx-dialog" id="ra-gpx-dialog" aria-labelledby="ra-gpx-heading">'
			. '<form method="post" enctype="multipart/form-data" action="' . $e($this->selfUrl()) . '">'
			. '<h2 id="ra-gpx-heading">Add a GPX route</h2>'
			. '<p class="ra-gpx-walk" id="ra-gpx-walk"></p>'
			. '<p class="ra-gpx-note" id="ra-gpx-note" hidden>This walk already has a route. The new file will replace it.</p>'
			. '<label for="ra-gpx-file" class="form-label">GPX file (up to ' . $maxMb . ' MB)</label>'
			. '<input type="file" name="gpx" id="ra-gpx-file" class="form-control" accept=".gpx,application/gpx+xml,text/xml" required>'
			. '<input type="hidden" name="walk" id="ra-gpx-id" value="">'
			. '<input type="hidden" name="ra_past_route" value="save">'
			. '<input type="hidden" name="' . $e(Session::getFormToken()) . '" value="1">'
			. '<div class="ra-gpx-actions">'
			. '<button type="button" class="btn btn-outline-secondary" id="ra-gpx-cancel">Cancel</button>'
			. '<button type="submit" class="btn btn-primary">Attach route</button>'
			. '</div></form></dialog>'
			. '<script>(function(){var walks=' . $json($walks) . ';'
			. 'var dlg=document.getElementById("ra-gpx-dialog");if(!dlg||typeof dlg.showModal!=="function"){return;}'
			. 'document.getElementById("ra-gpx-cancel").addEventListener("click",function(){dlg.close();});'
			. 'function open(id){var w=walks[id];document.getElementById("ra-gpx-id").value=id;'
			. 'document.getElementById("ra-gpx-walk").textContent=w[0];'
			. 'document.getElementById("ra-gpx-heading").textContent=w[1]?"Replace the GPX route":"Add a GPX route";'
			. 'document.getElementById("ra-gpx-note").hidden=!w[1];'
			. 'document.getElementById("ra-gpx-file").value="";dlg.showModal();}'
			// The library redraws the rows on every page, tab and search change, and the
			// template swaps in its own rows, so each new row is given its button as it appears.
			. 'function scan(){document.querySelectorAll(".ra-past-walks .ecr-walkrow:not([data-ra-gpx])").forEach(function(row){'
			. 'row.setAttribute("data-ra-gpx","1");var m=/displayWalkID\\(event,\'(\\d+)\'\\)/.exec(row.innerHTML);'
			. 'if(!m||!walks[m[1]]){return;}var id=m[1],b=document.createElement("button");'
			. 'b.type="button";b.className="btn btn-sm btn-outline-primary ra-gpx-btn";'
			. 'b.textContent=walks[id][1]?"Replace GPX":"Add GPX";b.setAttribute("aria-label",b.textContent+": "+walks[id][0]);'
			. 'b.addEventListener("click",function(ev){ev.preventDefault();ev.stopPropagation();open(id);});'
			. 'row.classList.add("ra-gpx-row");row.appendChild(b);});}'
			. 'document.addEventListener("DOMContentLoaded",function(){scan();'
			. 'new MutationObserver(scan).observe(document.body,{childList:true,subtree:true});});})();</script>';
	}

	/** "Wed 18 Nov 2026 – Tatton Park", for the GPX form. */
	private function walkLabel(object $item): string
	{
		$date = substr((string) ($item->start_date_time ?? ''), 0, 10);
		$title = self::plainTitle($item);
		$when = $date !== '' && strtotime($date) ? date('D j M Y', (int) strtotime($date)) : '';

		return trim($when . ($when !== '' && $title !== '' ? ' – ' : '') . $title);
	}

	/** The walk's title as plain text, without the "***" leaders put on a changed walk. */
	private static function plainTitle(object $item): string
	{
		$title = trim(html_entity_decode(strip_tags((string) ($item->title ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

		return trim((string) preg_replace('/^\*+\s*/', '', $title));
	}

	/**
	 * The Add GPX form posts back to this page. The walk must be one of the past walks shown
	 * here; the file gets the same checks as on the Upload a GPX page. Leaders may replace
	 * any walk's route, as they can there.
	 *
	 * @param array<int,object> $items
	 */
	private function handleUpload(array $items): void
	{
		$app = Factory::getApplication();
		$input = $app->getInput();

		if ($input->getMethod() !== 'POST' || $input->post->getString('ra_past_route', '') !== 'save') {
			return;
		}

		$back = $this->selfUrl();
		$done = static function (string $message, string $type) use ($app, $back): void {
			$app->enqueueMessage($message, $type);
			$app->redirect($back);
		};

		if (!$this->routes->enabled() || !$this->routes->canManage()) {
			$done('Sorry, only walk leaders can add routes to walks.', 'error');
		}

		if (!Session::checkToken('post')) {
			$done('Sorry, that form has expired. Please try again.', 'error');
		}

		$id = (string) preg_replace('/\D/', '', $input->post->getString('walk', ''));
		$chosen = null;

		foreach ($items as $item) {
			if ($id !== '' && (string) ($item->id ?? '') === $id) {
				$chosen = $item;
				break;
			}
		}

		if ($chosen === null) {
			$done('That walk is not in the list of past walks.', 'error');
		}

		$walk = [
			'id' => $id,
			'title' => self::plainTitle($chosen),
			'date' => substr((string) ($chosen->start_date_time ?? ''), 0, 10),
			'group' => (string) ($chosen->group_code ?? ''),
		];

		$problem = $this->routes->attachUpload($walk, (array) $input->files->get('gpx', [], 'array'));

		if ($problem !== '') {
			$done($problem, 'error');
		}

		$done('The route has been attached to "' . htmlspecialchars($this->walkLabel($chosen), ENT_QUOTES, 'UTF-8') . '".', 'message');
	}

	private function selfUrl(): string
	{
		$uri = clone Uri::getInstance();
		$uri->setFragment('');

		return $uri->toString(['path', 'query']);
	}

	/**
	 * The library's browser code only keeps walks from today on, both in the list it draws
	 * and in the store the walk popup reads (ra.walk.walks, made when its script loads). On
	 * this page both must keep them all. The library's scripts run before DOMContentLoaded
	 * (they are plain or deferred) and it draws the walks on window load, so the change goes
	 * in between.
	 */
	private function keepPastScript(): string
	{
		return '<script>document.addEventListener("DOMContentLoaded",function(){'
			. 'if(typeof ra==="undefined"||typeof ra.events!=="function"||ra.events.raKeepPast){return;}'
			. 'function keepAll(list){list.registerEvent=function(event){list.events.push(event);};}'
			// The search box: after the library's own filters, a walk must also contain every
			// word typed (see searchBox()).
			. 'function searchable(list){var own=list.setDisplayFilter;list.setDisplayFilter=function(){own.apply(list,arguments);'
			. 'var words=window.raPastWalksSearch||[];if(!words.length){return;}'
			. 'list.events.forEach(function(e){if(!e._displayFiltered){return;}if(e._raText===undefined){e._raText=raWalkText(e);}'
			. 'for(var i=0;i<words.length;i++){if(e._raText.indexOf(words[i])===-1){e._displayFiltered=false;return;}}});};}'
			. 'function raWalkText(e){var parts=[e.basics.title,e.basics.description,e.basics.descriptionHtml,e.basics.additionalNotes];'
			. '["meeting","start","finish","contacts"].forEach(function(k){((e[k]&&e[k].items)||[]).forEach(function(it){'
			. 'parts.push(it.description,it.postcode,it.gridref,it.contactName);});});'
			. 'var d=document.createElement("div");d.innerHTML=parts.filter(Boolean).join(" ");'
			. 'return (d.textContent||"").toLowerCase().replace(/\\s+/g," ");}'
			. 'var Events=ra.events;ra.events=function(){Events.apply(this,arguments);keepAll(this);searchable(this);};'
			. 'ra.events.prototype=Events.prototype;ra.events.raKeepPast=true;'
			. 'if(ra.walk&&ra.walk.walks){keepAll(ra.walk.walks);}'
			// The library adds the year to a date only for walks about a year ahead; past
			// walks always need it (the popup's date, the List tab's month headings).
			. 'if(ra.event&&typeof ra.event.basics==="function"){var Basics=ra.event.basics;'
			. 'ra.event.basics=function(){Basics.apply(this,arguments);var self=this;'
			. 'this.addYear=function(){return " "+ra.date.YYYY(ra.date.getDateTime(self.walkDate));};};'
			. 'ra.event.basics.prototype=Basics.prototype;}'
			. '});</script>'
			. $this->hideButtonsScript() . $this->osMapScript();
	}

	/**
	 * Maps on this page (the Map tab and the map in each walk popup) open on the same layer
	 * as the walk route pages: the plugin's "Default map" setting, OS Explorer/Landranger
	 * unless changed. The library draws each map only when it is shown, so every new layer
	 * switcher is set once as it appears. Explorer/Landranger is switched off at some zoom
	 * levels; then the Ordnance Survey (vector) layer is used.
	 */
	private function osMapScript(): string
	{
		$layer = trim((string) $this->params->get('routes_default_map', 'OS Explorer/Landranger'));

		if ($layer === '' || $layer === 'Open Street Map') {
			return '';
		}

		$name = json_encode($layer, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

		return '<script>(function(){var want=' . $name . ';'
			. 'function find(box,n){var labels=box.querySelectorAll("label");'
			. 'for(var i=0;i<labels.length;i++){if(labels[i].textContent.trim()===n){return labels[i].querySelector("input");}}return null;}'
			. 'function pick(box,tries){var input=find(box,want);'
			. 'if(input&&!input.disabled){if(!input.checked){input.click();}return;}'
			. 'if(tries>=12){var other=find(box,"Ordnance Survey");if(other&&!other.disabled&&!other.checked){other.click();}return;}'
			. 'window.setTimeout(function(){pick(box,tries+1);},250);}'
			. 'function scan(){document.querySelectorAll(".leaflet-control-layers-base").forEach(function(box){'
			. 'if(box.getAttribute("data-ra-os")){return;}box.setAttribute("data-ra-os","1");pick(box,0);});}'
			. 'document.addEventListener("DOMContentLoaded",function(){scan();'
			. 'new MutationObserver(scan).observe(document.body,{childList:true,subtree:true});});})();</script>';
	}

	/**
	 * A search box above the walks: leader, place, postcode, grid reference or any word in
	 * the walk's title or description. Every word typed must appear. It feeds the library's
	 * own filtering, so the list, calendar and map all narrow down together.
	 */
	private function searchBox(): string
	{
		return '<div class="ra-past-walks__search">'
			. '<label for="ra-past-walks-q" class="form-label">Search past walks</label>'
			. '<input type="search" id="ra-past-walks-q" class="form-control" autocomplete="off"'
			. ' placeholder="Leader, place, postcode or walk name">'
			. '</div>'
			. '<script>(function(){var box=document.getElementById("ra-past-walks-q"),timer=null;'
			. 'function run(){var q=box.value.toLowerCase().replace(/\\s+/g," ").trim();'
			. 'window.raPastWalksSearch=q?q.split(" "):[];document.dispatchEvent(new Event("reDisplayWalks"));'
			. '}'
			. 'box.addEventListener("input",function(){window.clearTimeout(timer);timer=window.setTimeout(run,250);});})();</script>';
	}

	/**
	 * Print and Add to Calendar make no sense for walks that have happened, and the Map tab's
	 * "where our walks are going to be" becomes "where our walks have been". The library draws
	 * them (in the list, and in the walk popup's header) without a class of their own, and
	 * draws them again whenever a tab or filter changes, so they are hidden by their label.
	 */
	private function hideButtonsScript(): string
	{
		return '<script>(function(){var labels=["Print","Add to Calendar"];'
			. 'function hide(){document.querySelectorAll(".ra-past-walks button,.ra-modal-header button").forEach(function(b){'
			. 'if(b.style.display!=="none"&&labels.indexOf((b.textContent||"").trim())!==-1){b.style.display="none";}});'
			// The Map tab's help line is written for coming walks.
			. 'document.querySelectorAll(".ra-past-walks p").forEach(function(p){'
			. 'if(p.textContent.indexOf("where our walks are going to be")===-1){return;}'
			. 'p.childNodes.forEach(function(t){if(t.nodeType===3){t.nodeValue=t.nodeValue.replace("where our walks are going to be","where our walks have been");}});});}'
			. 'document.addEventListener("DOMContentLoaded",function(){hide();'
			. 'new MutationObserver(hide).observe(document.body,{childList:true,subtree:true});});})();</script>';
	}

	/**
	 * Past walks for the group(s), as Walks Manager items (stdClass), from the stored years,
	 * fetching any year that is missing or due.
	 *
	 * @return array<int,object>
	 */
	private function items(string $groups): array
	{
		try {
			$this->ensureTable();
		} catch (\Throwable $e) {
			return [];
		}

		$tz = new \DateTimeZone('Europe/London');
		$today = new \DateTimeImmutable('today', $tz);
		$yesterday = $today->modify('-1 day');
		$first = max(2000, (int) $this->params->get('past_walks_from', 2023));
		$last = (int) $yesterday->format('Y');
		$items = [];

		for ($year = $first; $year <= $last; $year++) {
			$row = $this->load($groups, $year);

			if ($row === null || (!(int) $row->complete && strtotime((string) $row->fetched . ' UTC') < time() - 86400)) {
				$fresh = $this->fetchYear($groups, $year, $yesterday);

				if ($fresh !== null) {
					$settled = $today > (new \DateTimeImmutable($year . '-12-31', $tz))->modify('+' . self::SETTLE_DAYS . ' days');
					$this->save($groups, $year, $fresh, $settled);
					$row = (object) ['data' => json_encode($fresh), 'complete' => $settled ? 1 : 0];
				}
			}

			if ($row !== null) {
				foreach ((array) json_decode((string) $row->data) as $item) {
					if (\is_object($item) && !$this->isCancelled($item)
						&& substr((string) ($item->start_date_time ?? ''), 0, 10) <= $yesterday->format('Y-m-d')) {
						// Again on reading: years stored before this was added still have them.
						$items[] = $this->withoutContacts($item);
					}
				}
			}
		}

		return $items;
	}

	/**
	 * One year's walks from Walks Manager, up to yesterday, made ready to keep. Null when the
	 * feed could not be read (the stored copy, if any, is then kept as it is).
	 *
	 * @return array<int,object>|null
	 */
	private function fetchYear(string $groups, int $year, \DateTimeImmutable $yesterday): ?array
	{
		$end = min($year . '-12-31', $yesterday->format('Y-m-d'));
		$items = $this->fetchRange($groups, $year . '-01-01', $end);

		if ($items !== null && \count($items) >= self::FEED_LIMIT) {
			// A full answer may have been cut short: ask a month at a time instead.
			$items = [];

			for ($month = 1; $month <= 12; $month++) {
				$from = sprintf('%d-%02d-01', $year, $month);

				if ($from > $end) {
					break;
				}

				$part = $this->fetchRange($groups, $from, min(date('Y-m-t', strtotime($from)), $end));

				if ($part === null) {
					return null;
				}

				$items = array_merge($items, $part);
			}
		}

		if ($items === null) {
			return null;
		}

		$kept = [];

		foreach ($items as $item) {
			if (!\is_object($item) || $this->isCancelled($item)) {
				continue;
			}

			// The leader's name only: no phone number or contact form for past walks.
			if (isset($item->walk_leader) && \is_object($item->walk_leader)) {
				$item->walk_leader = (object) [
					'name' => (string) ($item->walk_leader->name ?? ''),
					'email_form' => '',
					'telephone' => null,
				];
			}

			$kept[(string) ($item->id ?? '')] = $this->withoutContacts($item);
		}

		unset($kept['']);

		return array_values($kept);
	}

	/**
	 * Phone numbers and email addresses that leaders typed into the walk's text (for example
	 * "Lift available from ... 07xxx xxxxxx") are blanked out: past walks show no contact
	 * details. Grid references and postcodes are left alone.
	 */
	private function withoutContacts(object $item): object
	{
		foreach (['title', 'description', 'additional_details'] as $field) {
			if (isset($item->{$field}) && \is_string($item->{$field})) {
				$item->{$field} = self::blankContacts($item->{$field});
			}
		}

		foreach (['meeting_location', 'start_location', 'end_location'] as $place) {
			if (isset($item->{$place}) && \is_object($item->{$place}) && isset($item->{$place}->description) && \is_string($item->{$place}->description)) {
				$item->{$place}->description = self::blankContacts($item->{$place}->description);
			}
		}

		return $item;
	}

	public static function blankContacts(string $text): string
	{
		$gone = '(contact details removed)';

		// Links first, so the link text goes with them.
		$text = (string) preg_replace('~<a\b[^>]*href\s*=\s*["\']?\s*(?:mailto|tel):[^>]*>.*?</a>~is', $gone, $text);
		$text = (string) preg_replace('~[A-Za-z0-9._%+\-]+(?:@|\s*\[at\]\s*|&#64;)[A-Za-z0-9.\-]+\.[A-Za-z]{2,}~', $gone, $text);

		// UK phone numbers: start 0 or +44, then digits in two or three groups (07xxx xxxxxx,
		// 0161 xxx xxxx, 01625 xxxxxx), 10 or 11 digits in all (12 allowed for a mistyped one). A grid square (two capitals, as in SJ 07512 17890) just before means it is a grid reference.
		return (string) preg_replace_callback(
			'~(?<!\b[A-Z]{2}\s)(?<!\b[A-Z]{2})(?<![\d.])(?:\+\s?44\s?(?:\(0\)\s?)?|\(?0)\d{2,5}\)?[\s.\-]?\d{3,4}[\s.\-]?\d{2,4}(?![\d])~',
			static function (array $m) use ($gone): string {
				$digits = preg_replace('/\D/', '', $m[0]);
				$digits = strpos($m[0], '+') !== false ? '0' . substr((string) $digits, 2) : (string) $digits;
				$digits = preg_replace('/^00/', '0', $digits);

				return \strlen((string) $digits) >= 10 && \strlen((string) $digits) <= 12 ? $gone : $m[0];
			},
			$text
		);
	}

	/**
	 * Cancelled in Walks Manager, or with "cancelled" in the title: some leaders mark a walk
	 * that way, and the Ramblers library then treats it as cancelled too.
	 */
	private function isCancelled(object $item): bool
	{
		return strtolower((string) ($item->status ?? '')) === 'cancelled'
			|| stripos((string) ($item->title ?? ''), 'cancelled') !== false;
	}

	/** @return array<int,object>|null */
	private function fetchRange(string $groups, string $from, string $to): ?array
	{
		$key = $this->routes->apiKey();

		if ($key === '' || $from > $to) {
			return $key === '' ? null : [];
		}

		$url = $this->routes->feedBase() . 'api-key=' . rawurlencode($key) . '&types=group-walk&groups=' . rawurlencode($groups)
			. '&date=' . $from . '&date_end=' . $to . '&limit=' . self::FEED_LIMIT;

		try {
			$response = HttpFactory::getHttp()->get($url, [], 30);
		} catch (\Throwable $e) {
			return null;
		}

		if ($response->getStatusCode() !== 200) {
			return null;
		}

		$data = json_decode((string) $response->getBody());

		if (!\is_object($data) || !isset($data->data) || !\is_array($data->data)) {
			return null;
		}

		return $data->data;
	}

	private function load(string $groups, int $year): ?object
	{
		$query = $this->db->getQuery(true)
			->select($this->db->quoteName(['data', 'complete', 'fetched']))
			->from($this->db->quoteName(self::TABLE))
			->where($this->db->quoteName('groups') . ' = :groups')
			->where($this->db->quoteName('year') . ' = :year')
			->bind(':groups', $groups)
			->bind(':year', $year, \Joomla\Database\ParameterType::INTEGER);
		$this->db->setQuery($query);
		$row = $this->db->loadObject();

		return $row ?: null;
	}

	private function save(string $groups, int $year, array $items, bool $complete): void
	{
		try {
			$this->db->setQuery(
				'REPLACE INTO ' . $this->db->quoteName(self::TABLE)
				. ' (`groups`, `year`, `data`, `walks`, `complete`, `fetched`) VALUES ('
				. $this->db->quote($groups) . ', ' . $year . ', ' . $this->db->quote(json_encode($items)) . ', '
				. \count($items) . ', ' . ($complete ? 1 : 0) . ', ' . $this->db->quote(Factory::getDate()->toSql()) . ')'
			)->execute();
		} catch (\Throwable $e) {
			// The page still shows what was fetched; it is fetched again next time.
		}
	}

	private function ensureTable(): void
	{
		$this->db->setQuery(
			'CREATE TABLE IF NOT EXISTS ' . $this->db->quoteName(self::TABLE) . ' ('
			. '`groups` VARCHAR(100) NOT NULL,'
			. '`year` SMALLINT NOT NULL,'
			. '`data` MEDIUMTEXT NOT NULL,'
			. '`walks` INT NOT NULL DEFAULT 0,'
			. '`complete` TINYINT NOT NULL DEFAULT 0,'
			. '`fetched` DATETIME NOT NULL,'
			. 'PRIMARY KEY (`groups`, `year`)'
			. ') DEFAULT CHARSET=utf8mb4'
		)->execute();
	}

	private function message(string $text): string
	{
		return '<p class="ra-past-walks__message">' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</p>';
	}
}
