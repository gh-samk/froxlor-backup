<?php

namespace Froxlor\Ext\Backup;

use Froxlor\Database\Database;
use Froxlor\Settings;

/**
 * State values of the backup in panel_settings (group 'ext_backup').
 *
 * Written directly via SQL instead of Settings::Set: the cron stores pure
 * state values here (last index refresh, check result) that never pass the
 * settings page and need no entry in the settings log. The rows are created
 * by the migration.
 */
class BackupSettings
{
	public const GROUP = 'ext_backup';

	public static function set(string $name, string $value): void
	{
		$stmt = Database::prepare("
			UPDATE `panel_settings` SET `value` = :v
			WHERE `settinggroup` = '" . self::GROUP . "' AND `varname` = :n
		");
		Database::pexecute($stmt, ['v' => $value, 'n' => $name]);
	}

	public static function get(string $name, string $default = ''): string
	{
		$value = Settings::Get(self::GROUP . '.' . $name);

		return $value === null || $value === false ? $default : (string)$value;
	}
}
