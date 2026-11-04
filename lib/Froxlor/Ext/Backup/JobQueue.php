<?php

namespace Froxlor\Ext\Backup;

use Froxlor\Database\Database;
use InvalidArgumentException;
use PDO;

/**
 * Queue of backup jobs in ext_backup_jobs.
 *
 * The panel only creates jobs; they are executed by the Froxlor cron
 * (Cron/BackupJobCron.php) as root. At most one job runs at a time: two
 * restic calls on the same repository should not overtake each other.
 *
 * Columns:
 *   type           setup | remove | check | index | backup | preview | restore
 *   status         queued | running | done | warning | failed
 *   subject        what the job is about: the schedule key of a backup
 *                  ("nightly 2026-11-03 01:00") or the restore parameters
 *                  as JSON (see RestoreJob). Never contains secrets.
 *   secret_params  one-time handover of credentials (setup only). Cleared
 *                  by the cron the moment it takes over the job, and never
 *                  read by any view.
 */
class JobQueue
{
	public const TABLE = 'ext_backup_jobs';

	public const TYPE_SETUP = 'setup';
	public const TYPE_REMOVE = 'remove';
	public const TYPE_CHECK = 'check';
	public const TYPE_INDEX = 'index';
	public const TYPE_BACKUP = 'backup';
	public const TYPE_PREVIEW = 'preview';
	public const TYPE_RESTORE = 'restore';

	public const STATUS_QUEUED = 'queued';
	public const STATUS_RUNNING = 'running';
	public const STATUS_DONE = 'done';
	public const STATUS_FAILED = 'failed';
	/** Finished, but with warnings worth a look (e.g. tables skipped). */
	public const STATUS_WARNING = 'warning';

	/** After this a running job counts as hung. */
	private const HUNG_AFTER = 1800;

	/** Backups and restores may run considerably longer (large mailboxes, first backup). */
	private const HUNG_AFTER_LONG = 28800;

	/** This many finished jobs stay in the history. */
	private const HISTORY = 100;

	/**
	 * The job types with their labels in the user's language.
	 *
	 * @return array<string,string>
	 */
	public static function types(): array
	{
		return [
			self::TYPE_SETUP => Lang::t('Set up storage target'),
			self::TYPE_REMOVE => Lang::t('Remove storage target'),
			self::TYPE_CHECK => Lang::t('Check connection'),
			self::TYPE_INDEX => Lang::t('Read restore points'),
			self::TYPE_BACKUP => Lang::t('Backup run'),
			self::TYPE_PREVIEW => Lang::t('Restore preview'),
			self::TYPE_RESTORE => Lang::t('Restoration'),
		];
	}

	/**
	 * Whether a backup job has already been created for this schedule slot,
	 * in whatever state.
	 */
	public static function slotTaken(string $key): bool
	{
		$stmt = Database::prepare('SELECT COUNT(*) AS `n` FROM `' . self::TABLE . '` WHERE `type` = :t AND `subject` = :s');
		$row = Database::pexecute_first($stmt, ['t' => self::TYPE_BACKUP, 's' => $key]);

		return (int)($row['n'] ?? 0) > 0;
	}

	/**
	 * Jobs for one customer (preview and restore), newest first. The
	 * parameters are JSON in "subject" and contain no secrets.
	 */
	public static function forCustomer(string $login, int $count = 10): array
	{
		$stmt = Database::prepare('
			SELECT `id`, `type`, `status`, `subject`, `created_by`, `created_at`, `finished_at`, `log`
			FROM `' . self::TABLE . '`
			WHERE `type` IN (:preview, :restore) AND `subject` LIKE :c
			ORDER BY `id` DESC LIMIT ' . max(1, $count));
		Database::pexecute($stmt, [
			'preview' => self::TYPE_PREVIEW,
			'restore' => self::TYPE_RESTORE,
			'c' => '%"customer":"' . $login . '"%',
		]);

		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
		foreach ($rows as &$r) {
			$p = json_decode((string)$r['subject'], true);
			// LIKE is only a pre-filter ("_" is a wildcard there); the exact match counts.
			$r['params'] = is_array($p) && ($p['customer'] ?? '') === $login ? $p : [];
			$r['type_label'] = self::types()[$r['type']] ?? $r['type'];
		}
		unset($r);

		return array_values(array_filter($rows, static fn($r) => $r['params'] !== []));
	}

	public static function find(int $id): ?array
	{
		$stmt = Database::prepare('SELECT `id`, `type`, `status`, `subject`, `log` FROM `' . self::TABLE . '` WHERE `id` = :i');
		$row = Database::pexecute_first($stmt, ['i' => $id]);
		if (!$row) {
			return null;
		}
		$p = json_decode((string)$row['subject'], true);
		$row['params'] = is_array($p) ? $p : [];

		return $row;
	}

	public static function isType(string $type): bool
	{
		return isset(self::types()[$type]);
	}

	/**
	 * @param array|null $secretParams stored as JSON and deleted by the cron
	 *                                 when it takes over the job (credentials)
	 * @return bool false if the same job is already queued or running
	 */
	public static function create(string $type, string $by, ?array $secretParams = null, ?string $subject = null): bool
	{
		if (!self::isType($type)) {
			throw new InvalidArgumentException(Lang::t('Unknown job: %s', $type));
		}

		$stmt = Database::prepare('
			SELECT COUNT(*) AS `n` FROM `' . self::TABLE . '`
			WHERE `type` = :t AND `status` IN (:queued, :running)
		');
		$row = Database::pexecute_first($stmt, ['t' => $type, 'queued' => self::STATUS_QUEUED, 'running' => self::STATUS_RUNNING]);
		if ((int)($row['n'] ?? 0) > 0) {
			return false;
		}

		$stmt = Database::prepare('
			INSERT INTO `' . self::TABLE . '` (`type`, `status`, `subject`, `secret_params`, `created_by`, `created_at`)
			VALUES (:t, :s, :subject, :p, :by, :at)
		');
		Database::pexecute($stmt, [
			't' => $type,
			's' => self::STATUS_QUEUED,
			'subject' => $subject,
			'p' => $secretParams === null ? null : json_encode($secretParams, JSON_UNESCAPED_UNICODE),
			'by' => $by,
			'at' => time(),
		]);

		return true;
	}

	/**
	 * The latest jobs, newest first. Without the column "secret_params":
	 * credentials never belong in a view.
	 */
	public static function history(int $count = 30): array
	{
		$stmt = Database::prepare('
			SELECT `id`, `type`, `status`, `subject`, `created_by`, `created_at`, `started_at`, `finished_at`, `log`
			FROM `' . self::TABLE . '`
			ORDER BY `id` DESC
			LIMIT ' . max(1, $count));
		Database::pexecute($stmt);

		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
		foreach ($rows as &$r) {
			$r['type_label'] = self::types()[$r['type']] ?? $r['type'];
		}
		unset($r);

		return $rows;
	}

	/**
	 * Takes the next queued job and marks it as running. Only if nothing
	 * else is running; the update is bound to status = 'queued' so that of
	 * two simultaneous cron processes only one gets it. The secret
	 * parameters are deleted from the database on takeover and only handed
	 * on in the return value.
	 */
	public static function next(): ?array
	{
		$stmt = Database::prepare('SELECT COUNT(*) AS `n` FROM `' . self::TABLE . '` WHERE `status` = :s');
		$row = Database::pexecute_first($stmt, ['s' => self::STATUS_RUNNING]);
		if ((int)($row['n'] ?? 0) > 0) {
			return null;
		}

		$stmt = Database::prepare('SELECT * FROM `' . self::TABLE . '` WHERE `status` = :s ORDER BY `id` ASC LIMIT 1');
		Database::pexecute($stmt, ['s' => self::STATUS_QUEUED]);
		$job = $stmt->fetch(PDO::FETCH_ASSOC);
		if (!$job) {
			return null;
		}

		$upd = Database::prepare('
			UPDATE `' . self::TABLE . '` SET `status` = :running, `started_at` = :at, `secret_params` = NULL
			WHERE `id` = :id AND `status` = :queued
		');
		Database::pexecute($upd, ['running' => self::STATUS_RUNNING, 'at' => time(), 'id' => $job['id'], 'queued' => self::STATUS_QUEUED]);
		if ($upd->rowCount() !== 1) {
			return null;
		}

		$job['secret_params'] = $job['secret_params'] === null ? [] : (json_decode((string)$job['secret_params'], true) ?: []);

		return $job;
	}

	public static function finish(int $id, bool $ok, string $log, bool $warning = false): void
	{
		// Keep only the end if a log gets out of hand.
		if (strlen($log) > 60000) {
			$log = Lang::t('… (truncated)') . "\n" . substr($log, -60000);
		}

		$stmt = Database::prepare('
			UPDATE `' . self::TABLE . '`
			SET `status` = :s, `finished_at` = :at, `log` = :log, `secret_params` = NULL
			WHERE `id` = :id
		');
		Database::pexecute($stmt, ['s' => !$ok ? self::STATUS_FAILED : ($warning ? self::STATUS_WARNING : self::STATUS_DONE), 'at' => time(), 'log' => $log, 'id' => $id]);

		// Limit the history. The double subquery works around MySQL's
		// "LIMIT in IN-subquery" and "same table in subquery" restrictions.
		$stmt = Database::prepare('
			DELETE FROM `' . self::TABLE . '`
			WHERE `status` IN (:done, :failed, :warning) AND `id` NOT IN (
				SELECT `id` FROM (
					SELECT `id` FROM `' . self::TABLE . '` ORDER BY `id` DESC LIMIT ' . self::HISTORY . '
				) AS `keep`
			)
		');
		Database::pexecute($stmt, ['done' => self::STATUS_DONE, 'failed' => self::STATUS_FAILED, 'warning' => self::STATUS_WARNING]);
	}

	/**
	 * Jobs that have been "running" for longer than 30 minutes (backups and
	 * restores: 8 hours) died with their process and would otherwise block
	 * the queue forever.
	 */
	public static function abortHung(): int
	{
		$stmt = Database::prepare('
			UPDATE `' . self::TABLE . '`
			SET `status` = :failed, `finished_at` = :at, `secret_params` = NULL,
			    `log` = CONCAT(COALESCE(`log`, \'\'), :note)
			WHERE `status` = :running
			  AND `started_at` < IF(`type` IN (:backup, :restore), :limit_long, :limit)
		');
		Database::pexecute($stmt, [
			'failed' => self::STATUS_FAILED,
			'at' => time(),
			'note' => "\n" . Lang::t('Aborted: ran for more than 30 minutes.'),
			'running' => self::STATUS_RUNNING,
			'backup' => self::TYPE_BACKUP,
			'restore' => self::TYPE_RESTORE,
			'limit' => time() - self::HUNG_AFTER,
			'limit_long' => time() - self::HUNG_AFTER_LONG,
		]);

		return $stmt->rowCount();
	}
}
