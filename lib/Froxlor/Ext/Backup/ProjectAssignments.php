<?php

namespace Froxlor\Ext\Backup;

/**
 * Optional: which database or FTP account belongs to which main domain.
 *
 * Froxlor itself cannot tell: panel_databases and ftp_users only carry a
 * customerid, no domainid. The Project View extension keeps such an
 * assignment. If it is installed and offers it, the restore page groups the
 * databases under their project and the manifest records the assignment.
 * Without it, every database appears under "Without a project" and the
 * backup works exactly the same.
 *
 * Expected provider (only used if it exists):
 *
 *     Froxlor\Ext\ProjectView\BackupIntegration::resourceAssignments(int $customerId): array
 *         list of ['domainid' => int, 'type' => 'database'|'ftp', 'id' => int]
 *
 * Nothing in here reads another extension's tables directly.
 */
final class ProjectAssignments
{
	public const PROVIDER = 'Froxlor\\Ext\\ProjectView\\BackupIntegration';

	/**
	 * @return array<int,array{domainid:int, type:string, id:int}>
	 */
	public static function forCustomer(int $customerId): array
	{
		try {
			if (!class_exists(self::PROVIDER) || !is_callable([self::PROVIDER, 'resourceAssignments'])) {
				return [];
			}
			$rows = call_user_func([self::PROVIDER, 'resourceAssignments'], $customerId);
		} catch (\Throwable $e) {
			// A broken provider must not break the backup: behave as if absent.
			return [];
		}
		if (!is_array($rows)) {
			return [];
		}

		$list = [];
		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}
			$domainId = self::positiveId($row['domainid'] ?? null);
			$id = self::positiveId($row['id'] ?? null);
			$type = $row['type'] ?? null;
			if ($domainId === null || $id === null || !in_array($type, ['database', 'ftp'], true)) {
				continue; // malformed entries are ignored
			}
			$list[] = ['domainid' => $domainId, 'type' => $type, 'id' => $id];
		}

		return $list;
	}

	/** A positive integer, also from a digit-only string (database values); otherwise null. */
	private static function positiveId(mixed $value): ?int
	{
		if (is_int($value)) {
			return $value > 0 ? $value : null;
		}
		if (is_string($value) && preg_match('~^[1-9][0-9]{0,18}$~', $value)) {
			return (int)$value;
		}

		return null;
	}

	/**
	 * Database id => main/sub domain id, for one customer.
	 *
	 * @return array<int,int>
	 */
	public static function databaseDomains(int $customerId): array
	{
		$map = [];
		foreach (self::forCustomer($customerId) as $row) {
			if ($row['type'] === 'database') {
				$map[$row['id']] = $row['domainid'];
			}
		}

		return $map;
	}
}
