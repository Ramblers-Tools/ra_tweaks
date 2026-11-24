<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.ra_tweaks
 *
 * A collection of old GPX routes (a folder of .gpx files, such as the one behind a "Walking
 * Routes" list page) linked to the Walks Manager walks that followed them. The links are a
 * JSON file: {"walk id": ["file.gpx", ...], ...}. On the past walks page those walks get a
 * badge, a tick box shows only them, and the walk popup gets a button for each old route.
 *
 * The links file must not sit inside the GPX folder: the Ramblers library rebuilds the
 * folder's statistics whenever a newer file appears there.
 */

namespace Ramblerseastcheshire\Plugin\System\RaTweaks\Walks;

\defined('_JEXEC') or die;

use Joomla\CMS\Uri\Uri;
use Joomla\Registry\Registry;

final class Collection
{
	private string $name;
	private string $folder;
	private string $links;
	private string $page;

	public function __construct(Registry $params)
	{
		$this->name = trim((string) $params->get('past_collection_name', ''));
		$this->folder = self::relative((string) $params->get('past_collection_folder', ''));
		$this->links = self::relative((string) $params->get('past_collection_links', ''));
		$this->page = trim((string) $params->get('past_collection_page', ''));
	}

	public function enabled(): bool
	{
		return $this->name !== '' && $this->folder !== '' && $this->links !== '';
	}

	public function name(): string
	{
		return $this->name;
	}

	/** The collection's own page (a menu link or path), for the tick box's label; may be ''. */
	public function page(): string
	{
		return $this->page;
	}

	/**
	 * Walk id => links to the old routes it follows, for the walks given. Files that are no
	 * longer in the folder are left out, and so is a walk left with none.
	 *
	 * @param array<int,object> $items  Walks Manager walks
	 *
	 * @return array<string,array<int,string>>
	 */
	public function forWalks(array $items): array
	{
		if (!$this->enabled()) {
			return [];
		}

		$file = JPATH_ROOT . '/' . $this->links;
		$links = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

		if (!\is_array($links)) {
			return [];
		}

		$base = rtrim(Uri::root(true), '/') . '/' . implode('/', array_map('rawurlencode', explode('/', $this->folder))) . '/';
		$out = [];

		foreach ($items as $item) {
			$id = (string) ($item->id ?? '');

			foreach ((array) ($links[$id] ?? []) as $name) {
				$name = basename((string) $name);

				if (preg_match('/\.gpx$/i', $name) && is_file(JPATH_ROOT . '/' . $this->folder . '/' . $name)) {
					$out[$id][] = $base . rawurlencode($name);
				}
			}
		}

		return $out;
	}

	/** A path below the site's top folder, without leading or trailing slashes, or '' if unsafe. */
	private static function relative(string $path): string
	{
		$path = trim(str_replace('\\', '/', trim($path)), '/');

		return ($path === '' || preg_match('~(^|/)\.\.(/|$)~', $path)) ? '' : $path;
	}
}
