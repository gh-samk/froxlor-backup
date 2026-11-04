<?php

namespace Froxlor\Ext\Backup;

use Froxlor\Database\Database;
use PDO;
use RuntimeException;

/**
 * Cache of the restic snapshots in ext_backup_index.
 *
 * The cron fills it (refresh), the interface only reads here. A page view
 * therefore needs neither restic nor a connection to the storage target, and
 * the panel never gets hold of credentials.
 *
 * It reads the tags the backup sets: "customer:<login>" and
 * "type:<customer|system|pre-restore>" (see Tags).
 */
class SnapshotIndex
{
	public const TABLE = 'ext_backup_index';

	/**
	 * Reads all snapshots from the repository and replaces the cache.
	 *
	 * @return int number of snapshots
	 * @throws RuntimeException if restic fails; the old state is kept then
	 */
	public static function refresh(): int
	{
		[$code, $out, $err] = Restic::run(['snapshots', '--json'], 600);
		if ($code !== 0) {
			throw new RuntimeException(Lang::t('restic snapshots: %s', trim(Restic::redact($err))));
		}

		$list = json_decode($out, true);
		if (!is_array($list)) {
			throw new RuntimeException(Lang::t('restic returned no readable snapshot list.'));
		}

		$rows = [];
		foreach ($list as $s) {
			if (!is_array($s) || empty($s['id'])) {
				continue;
			}
			$tags = array_values(array_filter((array)($s['tags'] ?? []), 'is_string'));
			$type = null;
			$customer = null;
			foreach ($tags as $tag) {
				if (str_starts_with($tag, Tags::TYPE_PREFIX)) {
					$type = substr($tag, strlen(Tags::TYPE_PREFIX), 32);
				} elseif (str_starts_with($tag, Tags::CUSTOMER_PREFIX)) {
					$customer = substr($tag, strlen(Tags::CUSTOMER_PREFIX), 64);
				}
			}
			$rows[] = [
				'id' => (string)$s['id'],
				'time' => (int)strtotime((string)($s['time'] ?? '')),
				'host' => substr((string)($s['hostname'] ?? ''), 0, 255),
				'type' => $type,
				'customer' => $customer,
				'tags' => json_encode($tags, JSON_UNESCAPED_UNICODE),
				'paths' => json_encode(array_values((array)($s['paths'] ?? [])), JSON_UNESCAPED_UNICODE),
			];
		}

		// Froxlor's Database passes transactions on to PDO via __callStatic;
		// the PDO object itself is not handed out.
		Database::beginTransaction();
		try {
			Database::query('DELETE FROM `' . self::TABLE . '`');
			$stmt = Database::prepare('
				INSERT INTO `' . self::TABLE . '` (`snapshot_id`, `snapshot_time`, `host`, `type`, `customer`, `tags`, `paths`)
				VALUES (:id, :time, :host, :type, :customer, :tags, :paths)
			');
			foreach ($rows as $r) {
				Database::pexecute($stmt, $r);
			}
			Database::commit();
		} catch (\Throwable $e) {
			Database::rollBack();
			throw $e;
		}

		BackupSettings::set('index_updated_at', (string)time());

		return count($rows);
	}

	/**
	 * The newest snapshots for the overview.
	 */
	public static function latest(int $count = 20): array
	{
		// LIMIT as a number in the string: PDO would quote a bound parameter.
		$stmt = Database::prepare('
			SELECT `snapshot_id`, `snapshot_time`, `host`, `type`, `customer`, `paths`, `tags`
			FROM `' . self::TABLE . '` ORDER BY `snapshot_time` DESC LIMIT ' . max(1, $count));
		Database::pexecute($stmt);

		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
		foreach ($rows as &$r) {
			$r['short_id'] = substr((string)$r['snapshot_id'], 0, 8);
			$r['paths'] = json_decode((string)$r['paths'], true) ?: [];
			$r['kind'] = self::kindLabel((string)($r['type'] ?? ''), (array)(json_decode((string)($r['tags'] ?? ''), true) ?: []));
		}
		unset($r);

		return $rows;
	}

	/**
	 * Readable kind of a restore point: "Nightly backup", "Intermediate backup"
	 * or "Before a restore".
	 */
	public static function kindLabel(string $type, array $tags): string
	{
		if ($type === Tags::TYPE_PRE_RESTORE) {
			return Lang::t('Before a restore');
		}

		if (in_array(Tags::run(Tags::RUN_NIGHTLY), $tags, true)) {
			return Lang::t('Nightly backup');
		}

		return in_array(Tags::WITHOUT_MAIL, $tags, true) ? Lang::t('Intermediate backup, without mailboxes') : Lang::t('Intermediate backup');
	}

	/**
	 * @return array{count:int, newest:int}
	 */
	public static function stats(): array
	{
		$stmt = Database::prepare('SELECT COUNT(*) AS `n`, COALESCE(MAX(`snapshot_time`), 0) AS `newest` FROM `' . self::TABLE . '`');
		$row = Database::pexecute_first($stmt);

		return ['count' => (int)($row['n'] ?? 0), 'newest' => (int)($row['newest'] ?? 0)];
	}

	/**
	 * Customers with restore points.
	 *
	 * @return array<int,array{customer:string, count:int, newest:int}>
	 */
	public static function customers(): array
	{
		$stmt = Database::prepare("
			SELECT `customer`, COUNT(*) AS `n`, MAX(`snapshot_time`) AS `newest`
			FROM `" . self::TABLE . "` WHERE `type` = :t AND `customer` IS NOT NULL
			GROUP BY `customer` ORDER BY `customer` ASC
		");
		Database::pexecute($stmt, ['t' => Tags::TYPE_CUSTOMER]);

		return array_map(static fn(array $r): array => [
			'customer' => (string)$r['customer'],
			'count' => (int)$r['n'],
			'newest' => (int)$r['newest'],
		], $stmt->fetchAll(PDO::FETCH_ASSOC));
	}

	/**
	 * Time of the newest customer snapshot of one customer, 0 if none.
	 */
	public static function newestFor(string $login): int
	{
		$stmt = Database::prepare('
			SELECT COALESCE(MAX(`snapshot_time`), 0) AS `newest` FROM `' . self::TABLE . '`
			WHERE `customer` = :c AND `type` = :t
		');
		$row = Database::pexecute_first($stmt, ['c' => $login, 't' => Tags::TYPE_CUSTOMER]);

		return (int)($row['newest'] ?? 0);
	}

	/**
	 * Restore points of one customer of one type ("customer" or
	 * "pre-restore"), newest first.
	 */
	public static function pointsFor(string $login, string $type, int $count = 60): array
	{
		$stmt = Database::prepare('
			SELECT `snapshot_id`, `snapshot_time`, `paths`, `tags` FROM `' . self::TABLE . '`
			WHERE `customer` = :c AND `type` = :t ORDER BY `snapshot_time` DESC LIMIT ' . max(1, $count));
		Database::pexecute($stmt, ['c' => $login, 't' => $type]);

		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
		foreach ($rows as &$r) {
			$r['short_id'] = substr((string)$r['snapshot_id'], 0, 8);
			$r['paths'] = json_decode((string)$r['paths'], true) ?: [];
			$r['kind'] = self::kindLabel($type, (array)(json_decode((string)$r['tags'], true) ?: []));
		}
		unset($r);

		return $rows;
	}

	public static function hasPoint(string $id, string $login): bool
	{
		$stmt = Database::prepare('SELECT COUNT(*) AS `n` FROM `' . self::TABLE . '` WHERE `snapshot_id` = :i AND `customer` = :c');
		$row = Database::pexecute_first($stmt, ['i' => $id, 'c' => $login]);

		return (int)($row['n'] ?? 0) > 0;
	}

	/**
	 * Backups of the server (panel database, configuration), newest first.
	 */
	public static function systemPoints(int $count = 30): array
	{
		$stmt = Database::prepare('
			SELECT `snapshot_id`, `snapshot_time`, `tags` FROM `' . self::TABLE . '`
			WHERE `type` = :t ORDER BY `snapshot_time` DESC LIMIT ' . max(1, $count));
		Database::pexecute($stmt, ['t' => Tags::TYPE_SYSTEM]);

		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
		foreach ($rows as &$r) {
			$r['kind'] = self::kindLabel(Tags::TYPE_SYSTEM, (array)(json_decode((string)$r['tags'], true) ?: []));
		}
		unset($r);

		return $rows;
	}

	public static function hasSystemPoint(string $id): bool
	{
		$stmt = Database::prepare('SELECT COUNT(*) AS `n` FROM `' . self::TABLE . '` WHERE `snapshot_id` = :i AND `type` = :t');
		$row = Database::pexecute_first($stmt, ['i' => $id, 't' => Tags::TYPE_SYSTEM]);

		return (int)($row['n'] ?? 0) > 0;
	}
}
