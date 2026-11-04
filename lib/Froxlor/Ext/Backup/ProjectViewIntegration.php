<?php

namespace Froxlor\Ext\Backup;

/**
 * The backup box on the project page of the Project View extension.
 *
 * This is the only interface other extensions may rely on. Project View
 * shows its box only if this class exists (class_exists), formats the date
 * itself and links to admin_backup.php with restoreParams(). Neither side
 * requires the other to be installed.
 */
final class ProjectViewIntegration
{
	/** A customer backup older than this counts as overdue: daily plus slack. */
	public const STALE_AFTER = 26 * 3600;

	/**
	 * Backup state of one customer.
	 *
	 * @param array $userinfo the logged-in admin (Froxlor's $userinfo)
	 * @return array{configured:bool, last_backup:?int, stale:bool, allowed:bool}
	 *         last_backup: unix timestamp of the newest customer snapshot, or null;
	 *         stale: configured and no backup within STALE_AFTER;
	 *         allowed: the admin may use the backup pages (change_serversettings)
	 */
	public static function status(string $customerLogin, array $userinfo): array
	{
		$allowed = (int)($userinfo['change_serversettings'] ?? 0) === 1;
		$configured = false;
		$newest = 0;
		try {
			$configured = BackupSettings::get('configured', '0') === '1';
			if ($configured) {
				$newest = SnapshotIndex::newestFor($customerLogin);
			}
		} catch (\Throwable $e) {
			// Files installed but database not migrated: report "not configured"
			// instead of breaking the project page.
			$configured = false;
			$newest = 0;
		}

		return [
			'configured' => $configured,
			'last_backup' => $newest > 0 ? $newest : null,
			'stale' => $configured && ($newest === 0 || time() - $newest > self::STALE_AFTER),
			'allowed' => $allowed,
		];
	}

	/**
	 * Query parameters for the restore page link; the caller adds the
	 * section (link to admin_backup.php).
	 *
	 * "scope" preselects what is ticked for this project: all (default), or
	 * web, mail or db. The caller may override it.
	 *
	 * @return array{page:string, customer:string, project:int, scope:string}
	 */
	public static function restoreParams(string $customerLogin, int $domainId): array
	{
		return [
			'page' => 'restore',
			'customer' => $customerLogin,
			'project' => $domainId,
			'scope' => 'all',
		];
	}
}
