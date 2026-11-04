<?php

/**
 * Bilingual user interface (English/German) for the Backup extension.
 *
 * The English source text doubles as the key:
 *
 *     Lang::t('Restore now')              PHP
 *     {{ backup_t('Restore now') }}       Twig
 *     Lang::t('%d days', $n)              with placeholders (sprintf)
 *
 * In English the text is returned unchanged; in German the translation from
 * lang/de.php (key = English text) is used. A missing translation falls back
 * to the English text, never to an empty field or a key name.
 *
 * Why not Froxlor's lng(): its language files (lng/*.lng.php) are hard-wired
 * and part of Froxlor; entries there would be a patch. This layer brings its
 * own catalog.
 *
 * Which language applies: the one of the logged-in user (like Froxlor
 * itself: own language, otherwise the account's default language, otherwise
 * the panel default). "de"/"Deutsch" gives German, any other language
 * English. Without a session (cron jobs) the panel default language applies.
 */

namespace Froxlor\Ext\Backup;

use Froxlor\CurrentUser;
use Froxlor\Settings;

final class Lang
{
	/** Name of the Twig function. Unique per extension, so several can be installed side by side. */
	public const TWIG_FUNCTION = 'backup_t';

	private static ?string $iso = null;

	/** @var array<string,string>|null */
	private static ?array $catalog = null;

	/** Translates an English text; with arguments, sprintf is applied. */
	public static function t(string $en, mixed ...$args): string
	{
		$text = $en;
		if (self::iso() === 'de') {
			$text = self::catalog()[$en] ?? $en;
		}

		return $args ? vsprintf($text, $args) : $text;
	}

	/** 'de' or 'en'. */
	public static function iso(): string
	{
		if (self::$iso !== null) {
			return self::$iso;
		}
		$language = null;
		try {
			if (class_exists(CurrentUser::class) && CurrentUser::hasSession()) {
				$language = CurrentUser::getField('language');
				if (empty($language)) {
					$language = CurrentUser::getField('def_language');
				}
			}
			if (empty($language)) {
				$language = Settings::Get('panel.standardlanguage');
			}
		} catch (\Throwable $e) {
			$language = null;
		}

		return self::$iso = self::normalize($language);
	}

	/** For tests and cron jobs: force a language (null = detect again). */
	public static function set(?string $language): void
	{
		self::$iso = $language === null ? null : self::normalize($language);
	}

	/** Registers the Twig function backup_t(); safe to call more than once. */
	public static function registerTwig(\Twig\Environment $twig): void
	{
		static $done = [];
		$id = spl_object_id($twig);
		if (isset($done[$id])) {
			return;
		}
		$twig->addFunction(new \Twig\TwigFunction(self::TWIG_FUNCTION, static fn(string $en, mixed ...$args): string => self::t($en, ...$args)));
		$done[$id] = true;
	}

	/** @return array<string,string> English => German */
	public static function catalog(): array
	{
		if (self::$catalog === null) {
			$catalog = require __DIR__ . '/lang/de.php';
			self::$catalog = is_array($catalog) ? $catalog : [];
		}

		return self::$catalog;
	}

	private static function normalize(mixed $language): string
	{
		$s = strtolower(trim((string)$language));

		return ($s === 'de' || str_starts_with($s, 'de-') || str_starts_with($s, 'de_') || $s === 'deutsch') ? 'de' : 'en';
	}
}
