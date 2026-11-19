<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.ra_tweaks
 *
 * Photo Gallery (Event Gallery) albums for past walks. Albums are not linked to walks, so they
 * are matched by date and kind of walk: most album names are the walk's date and its type,
 * such as "2026-11-14-long" or "14-11-2026-medium". An album matches when
 *  - it is the only walk that day, or
 *  - just one walk that day is of the album's type (short, medium, long, stroller, evening;
 *    a walk's type comes from its title, or from its distance), or
 *  - just one of those walks mentions a place named in the album's name or description.
 * Albums that are not walks (old photos, footpath work, trips away) find no walk and are left
 * out. Matching runs on each page view, so a new album gets its link without anything else to do.
 */

namespace Ramblerseastcheshire\Plugin\System\RaTweaks\Walks;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Router\Route;
use Joomla\Database\DatabaseInterface;

final class PhotoAlbums
{
	private const TYPES = ['stroller', 'evening', 'short', 'medium', 'long'];

	/** Words in album names that say nothing about the place. */
	private const COMMON = ['short', 'medium', 'stroller', 'evening', 'circular', 'walks', 'around', 'along',
		'there', 'january', 'february', 'march', 'april', 'june', 'july', 'august', 'september', 'october',
		'november', 'december', 'led'];

	private DatabaseInterface $db;

	public function __construct(DatabaseInterface $db)
	{
		$this->db = $db;
	}

	/**
	 * Walk id => the links to its albums (most photos first).
	 *
	 * @param array<int,object> $items Walks Manager items (past walks)
	 * @return array<string,array<int,string>>
	 */
	public function forWalks(array $items): array
	{
		$albums = $this->albums();

		if (!$albums) {
			return [];
		}

		$byDay = [];

		foreach ($items as $item) {
			$id = (string) ($item->id ?? '');
			$day = substr((string) ($item->start_date_time ?? ''), 0, 10);

			if ($id === '' || $day === '') {
				continue;
			}

			$title = strtolower(strip_tags((string) ($item->title ?? '')));
			$text = $title;

			foreach (['start_location', 'meeting_location'] as $place) {
				if (isset($item->{$place}->description) && \is_string($item->{$place}->description)) {
					$text .= ' ' . strtolower(strip_tags($item->{$place}->description));
				}
			}

			$byDay[$day][] = ['id' => $id, 'type' => $this->walkType($title, (float) ($item->distance_km ?? 0)), 'text' => $text];
		}

		$found = [];

		foreach ($albums as $album) {
			$id = $this->match($album, $byDay);

			if ($id !== null) {
				$found[$id][] = $album;
			}
		}

		$out = [];

		foreach ($found as $id => $list) {
			usort($list, static fn ($a, $b): int => $b['files'] <=> $a['files']);
			$out[$id] = array_map(static fn ($a): string => $a['url'], $list);
		}

		return $out;
	}

	/** @param array<string,array<int,array{id:string,type:string,text:string}>> $byDay */
	private function match(array $album, array $byDay): ?string
	{
		foreach ($album['dates'] as $day) {
			$walks = $byDay[$day] ?? [];

			if (!$walks) {
				continue;
			}

			if (\count($walks) === 1) {
				return $walks[0]['id'];
			}

			$sameType = $album['type'] === '' ? [] : array_values(array_filter($walks, static fn ($w): bool => $w['type'] === $album['type']));

			if (\count($sameType) === 1) {
				return $sameType[0]['id'];
			}

			$words = $album['words'];
			$named = array_values(array_filter($sameType ?: $walks, static function ($w) use ($words): bool {
				foreach ($words as $word) {
					if (strpos($w['text'], $word) !== false) {
						return true;
					}
				}

				return false;
			}));

			if (\count($named) === 1) {
				return $named[0]['id'];
			}

			// Several walks that day and no way to tell: no link rather than a wrong one.
			return null;
		}

		return null;
	}

	private function walkType(string $text, float $km): string
	{
		foreach (self::TYPES as $type) {
			if (preg_match('/\b' . $type . '/', $text)) {
				return $type;
			}
		}

		return $km <= 0 ? '' : ($km < 11 ? 'short' : ($km < 17 ? 'medium' : 'long'));
	}

	/**
	 * Published, visible albums with at least one photo.
	 *
	 * @return array<int,array{url:string,files:int,dates:array<int,string>,type:string,words:array<int,string>}>
	 */
	private function albums(): array
	{
		$now = Factory::getDate()->toSql();

		try {
			$query = $this->db->getQuery(true)
				->select(['f.folder', 'f.date', 'f.description', 'f.catid', 'COUNT(x.id) AS files'])
				->from($this->db->quoteName('#__eventgallery_folder', 'f'))
				->join('INNER', $this->db->quoteName('#__eventgallery_file', 'x') . ' ON x.folder = f.folder AND x.published = 1')
				->where('f.published = 1')
				->where("(f.password IS NULL OR f.password = '')")
				->where("(f.usergroupids IS NULL OR f.usergroupids = '')")
				->where('(f.publish_up IS NULL OR f.publish_up <= ' . $this->db->quote($now) . ')')
				->where('(f.publish_down IS NULL OR f.publish_down > ' . $this->db->quote($now) . ')')
				->group('f.folder, f.date, f.description, f.catid');
			$this->db->setQuery($query);
			$rows = (array) $this->db->loadObjectList();
		} catch (\Throwable $e) {
			// No Photo Gallery on this site.
			return [];
		}

		$menus = $this->galleryMenus();
		$out = [];

		foreach ($rows as $row) {
			$name = strtolower((string) $row->folder);
			$description = json_decode((string) $row->description, true);
			$description = strtolower(strip_tags(\is_array($description) ? implode(' ', array_map('strval', $description)) : (string) $row->description));
			$both = $name . ' ' . $description;

			$type = '';

			foreach (self::TYPES as $t) {
				if (strpos($both, $t) !== false) {
					$type = $t;
					break;
				}
			}

			$words = array_values(array_filter(
				preg_split('/[^a-z]+/', $both) ?: [],
				static fn ($w): bool => \strlen($w) >= 5 && !\in_array($w, self::COMMON, true)
			));

			$out[] = [
				'url' => Route::_('index.php?option=com_eventgallery&view=event&folder=' . rawurlencode((string) $row->folder)
					. $this->itemid((int) $row->catid, $menus)),
				'files' => (int) $row->files,
				'dates' => $this->dates($name, (string) $row->date),
				'type' => $type,
				'words' => $words,
			];
		}

		return $out;
	}

	/**
	 * The Photo Gallery's menu items, so an album's link is its address in the gallery
	 * (/photo-gallery/2026/may/event/...): category id => menu item id, 0 for the top-level
	 * gallery page, plus each category's parent for categories without their own item.
	 *
	 * @return array{items:array<int,int>,parents:array<int,int>}
	 */
	private function galleryMenus(): array
	{
		$items = [];
		$parents = [];

		try {
			$this->db->setQuery(
				$this->db->getQuery(true)
					->select(['id', 'link'])
					->from($this->db->quoteName('#__menu'))
					->where('published = 1')
					->where('client_id = 0')
					->where($this->db->quoteName('link') . ' LIKE ' . $this->db->quote('index.php?option=com_eventgallery&view=categories%'))
					->order('id')
			);

			foreach ((array) $this->db->loadObjectList() as $menu) {
				parse_str((string) parse_url((string) $menu->link, PHP_URL_QUERY), $query);
				$cat = (string) ($query['catid'] ?? 'root');
				$key = $cat === 'root' ? 0 : (int) $cat;
				$items[$key] = $items[$key] ?? (int) $menu->id;
			}

			$this->db->setQuery(
				$this->db->getQuery(true)
					->select(['id', 'parent_id'])
					->from($this->db->quoteName('#__categories'))
					->where($this->db->quoteName('extension') . ' = ' . $this->db->quote('com_eventgallery'))
			);

			foreach ((array) $this->db->loadObjectList() as $cat) {
				$parents[(int) $cat->id] = (int) $cat->parent_id;
			}
		} catch (\Throwable $e) {
			// Links then fall back to Joomla's own address for the album.
		}

		return ['items' => $items, 'parents' => $parents];
	}

	/** "&Itemid=…" for the album's category, its nearest parent with a menu item, or the gallery. */
	private function itemid(int $catid, array $menus): string
	{
		for ($cat = $catid, $hops = 0; $cat > 1 && $hops < 10; $cat = $menus['parents'][$cat] ?? 0, $hops++) {
			if (isset($menus['items'][$cat])) {
				return '&Itemid=' . $menus['items'][$cat];
			}
		}

		return isset($menus['items'][0]) ? '&Itemid=' . $menus['items'][0] : '';
	}

	/**
	 * The dates an album may be for: from its name (2026-11-14, 14-11-2026 or 141126), then its
	 * date field.
	 *
	 * @return array<int,string>
	 */
	private function dates(string $name, string $field): array
	{
		$dates = [];

		if (preg_match('/(20\d\d)-(\d{1,2})-(\d{1,2})/', $name, $m)) {
			$dates[] = sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
		}

		if (preg_match('/(\d{1,2})-(\d{1,2})-(20\d\d)/', $name, $m)) {
			$dates[] = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
		}

		if (preg_match('/(\d\d)(\d\d)(\d\d)$/', $name, $m)) {
			$dates[] = sprintf('20%02d-%02d-%02d', $m[3], $m[2], $m[1]);
		}

		if ($field !== '') {
			$dates[] = substr($field, 0, 10);
		}

		return array_values(array_unique(array_filter($dates, static fn ($d): bool => checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4)))));
	}
}
