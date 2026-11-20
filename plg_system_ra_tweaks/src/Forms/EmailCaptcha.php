<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.ra_tweaks
 */

namespace Ramblerseastcheshire\Plugin\System\RaTweaks\Forms;

\defined('_JEXEC') or die;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Document\HtmlDocument;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Plugin\PluginHelper;

/**
 * Spam check for the Ramblers Tools email form (the Email buttons on contact pages, which
 * anyone can use). The form has no captcha of its own, so this adds the site's captcha field
 * to it (Joomla then checks it on Send) and shows it above the Send button.
 *
 * It uses whichever captcha the site is set to (Global Configuration > Default Captcha, or the
 * menu item's own), so it works with an invisible guard or Cloudflare Turnstile alike. With no
 * captcha set, or the chosen captcha plugin switched off, it does nothing.
 */
final class EmailCaptcha
{
	public const FORM = 'com_ra_tools.email';

	private const MARKER = 'ra-tweaks-captcha';

	private const FIELD = '<field name="captcha" type="captcha" label="Spam check" validate="captcha" namespace="ra_tools_email" />';

	public function __construct(private CMSApplicationInterface $app)
	{
	}

	/** The captcha plugin in use on this page, or '' for none. */
	private function plugin(): string
	{
		$plugin = (string) $this->app->get('captcha', '');

		if ($this->app->isClient('site') && method_exists($this->app, 'getParams')) {
			$plugin = (string) $this->app->getParams()->get('captcha', $plugin);
		}

		if ($plugin === '' || $plugin === '0' || !PluginHelper::isEnabled('captcha', $plugin)) {
			return '';
		}

		return $plugin;
	}

	/** onContentPrepareForm: add the captcha field, so the form's own validation checks it. */
	public function addField($form): void
	{
		if (!$form instanceof Form || $form->getName() !== self::FORM || $this->plugin() === '' || $form->getField('captcha')) {
			return;
		}

		$form->setField(new \SimpleXMLElement(self::FIELD));
	}

	/** onBeforeRender: show the captcha in the form, above its Send button. */
	public function render(): void
	{
		$doc = $this->app->getDocument();

		if (!$doc instanceof HtmlDocument || $this->app->getInput()->getCmd('option') !== 'com_ra_tools' || $this->plugin() === '') {
			return;
		}

		$buffer = $doc->getBuffer('component');

		if (!\is_string($buffer) || strpos($buffer, 'id="form-email"') === false || strpos($buffer, self::MARKER) !== false) {
			return;
		}

		$form = new Form(self::FORM, ['control' => 'jform']);
		$form->load('<form>' . self::FIELD . '</form>');
		$html = '<div class="' . self::MARKER . '">' . $form->renderField('captcha') . '</div>';

		$start = strpos($buffer, 'id="form-email"');
		$head  = substr($buffer, 0, $start);
		$tail  = substr($buffer, $start);
		$count = 0;
		$tail  = preg_replace_callback(
			'/<div class="control-group">\s*<div class="controls">\s*<button type="submit"/',
			static fn (array $m): string => $html . $m[0],
			$tail,
			1,
			$count
		);

		if (!$count) {
			$end = strpos($tail, '</form>');

			if ($end === false) {
				return;
			}

			$tail = substr($tail, 0, $end) . $html . substr($tail, $end);
		}

		$doc->setBuffer($head . $tail, ['type' => 'component']);
	}
}
