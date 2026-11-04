<?php

namespace Froxlor\Ext\Backup\Cron;

use Froxlor\Cron\FroxlorCron;
use Froxlor\Database\Database;
use Froxlor\Ext\Backup\BackupRun;
use Froxlor\Ext\Backup\BackupSettings;
use Froxlor\Ext\Backup\JobQueue;
use Froxlor\Ext\Backup\Lang;
use Froxlor\Ext\Backup\RestoreJob;
use Froxlor\Ext\Backup\Restic;
use Froxlor\Ext\Backup\Schedule;
use Froxlor\Ext\Backup\SnapshotIndex;
use Froxlor\Ext\Backup\Target;
use Froxlor\FroxlorLogger;
use RuntimeException;

/**
 * Processes the backup jobs created in the interface.
 *
 * Registered in cronjobs_run with interval "1 MINUTE" (migrations/001). The
 * cron also creates an hourly job to refresh the restore points by itself as
 * soon as a storage target is set up, and the scheduled backups.
 *
 * Messages to Froxlor's logger are plain ASCII on purpose: it mangles
 * non-ASCII characters in the cron output.
 */
class BackupJobCron extends FroxlorCron
{
	/** No new job is started after this many seconds. */
	private const BUDGET = 240;

	/** The index refreshes itself this often. */
	private const INDEX_MAX_AGE = 3600;

	public static function run()
	{
		$logger = FroxlorLogger::getInstanceOf();

		if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
			$logger->logAction(FroxlorLogger::CRON_ACTION, LOG_WARNING, 'Backup: cron is not running as root, skipped');
			return;
		}

		$hung = JobQueue::abortHung();
		if ($hung > 0) {
			$logger->logAction(FroxlorLogger::CRON_ACTION, LOG_WARNING, sprintf('Backup: %d hung jobs aborted', $hung));
		}

		if (BackupSettings::get('configured', '0') === '1'
			&& time() - (int)BackupSettings::get('index_updated_at', '0') > self::INDEX_MAX_AGE) {
			JobQueue::create(JobQueue::TYPE_INDEX, 'cron');
		}

		if (BackupSettings::get('configured', '0') === '1') {
			self::planBackups();
		}

		// The time budget only applies to starting further jobs; a backup
		// itself may run for a long time.
		$end = time() + self::BUDGET;
		while (time() < $end && ($job = JobQueue::next()) !== null) {
			$warning = false;
			try {
				$result = self::execute($job);
				[$ok, $log] = $result;
				$warning = (bool)($result[2] ?? false);
			} catch (\Throwable $e) {
				$ok = false;
				$log = Lang::t('ERROR: %s', Restic::redact($e->getMessage()));
			}

			JobQueue::finish((int)$job['id'], $ok, $log, $warning);
			$logger->logAction(
				FroxlorLogger::CRON_ACTION,
				$ok && !$warning ? LOG_NOTICE : LOG_WARNING,
				sprintf('Backup: job %d (%s) %s', $job['id'], $job['type'], $ok ? ($warning ? 'done with warnings' : 'done') : 'failed')
			);
		}
	}

	/**
	 * Creates exactly one backup job for every due slot. The slot is the job
	 * subject, so a slot never gets a second job, however often the cron
	 * comes by.
	 */
	private static function planBackups(): void
	{
		$due = Schedule::due(
			BackupSettings::get('intermediate_times', '00:00,08:00,12:00,18:00'),
			BackupSettings::get('nightly_time', '01:00'),
			time()
		);
		foreach ($due as $slot) {
			if (!JobQueue::slotTaken($slot['key'])) {
				JobQueue::create(JobQueue::TYPE_BACKUP, 'schedule', null, $slot['key']);
			}
		}
	}

	/**
	 * @return array{0:bool, 1:string, 2?:bool} success, log, warnings
	 */
	private static function execute(array $job): array
	{
		switch ($job['type']) {
			case JobQueue::TYPE_SETUP:
				return self::setup((array)$job['secret_params']);
			case JobQueue::TYPE_REMOVE:
				Target::remove();
				BackupSettings::set('configured', '0');
				BackupSettings::set('target_type', '');
				BackupSettings::set('target_label', '');
				BackupSettings::set('target_details', '');
				BackupSettings::set('last_check_ok', '0');
				BackupSettings::set('index_updated_at', '0');
				Database::query('DELETE FROM `' . SnapshotIndex::TABLE . '`');
				return [true, implode("\n", [
					Lang::t('Storage target removed: credentials and password have been deleted from the server.'),
					Lang::t('The backups in the storage itself are untouched.'),
					Lang::t('Scheduled backups will only run again after a new setup.'),
				])];
			case JobQueue::TYPE_CHECK:
				return self::check();
			case JobQueue::TYPE_INDEX:
				$n = SnapshotIndex::refresh();
				return [true, Lang::t('%d restore points read.', $n)];
			case JobQueue::TYPE_PREVIEW:
				return RestoreJob::run($job, true);
			case JobQueue::TYPE_RESTORE:
				$result = RestoreJob::run($job, false);
				JobQueue::create(JobQueue::TYPE_INDEX, 'cron');
				return $result;
			case JobQueue::TYPE_BACKUP:
				$result = BackupRun::run(Schedule::runFrom($job['subject'] ?? null));
				// Make new points visible right away.
				JobQueue::create(JobQueue::TYPE_INDEX, 'cron');
				return $result;
		}

		return [false, Lang::t('Unknown job type: %s', $job['type'])];
	}

	/**
	 * Writes the target, creates the repository (or adopts an existing one)
	 * and records the state.
	 */
	private static function setup(array $p): array
	{
		$type = (string)($p['type'] ?? '');
		$target = Target::validate($type, (array)($p['target'] ?? []));
		$password = (string)($p['password'] ?? '');
		// Only change the credentials, keep the repository with its password.
		if ($password === '' && !empty($p['keep_password'])) {
			$password = Target::previousPassword();
		}

		$log = [Lang::t('Target: %s', $target['label'])];

		// Keep the previous files: if the setup fails, the previous target
		// stays usable.
		$previous = [];
		foreach ([Target::ENV_FILE, Target::PASSWORD_FILE] as $file) {
			$previous[$file] = is_file($file) ? (string)file_get_contents($file) : null;
		}

		try {
			Target::write($target, $password);

			[$code, , $err] = Restic::run(['cat', 'config'], 120);
			if ($code === 0) {
				$log[] = Lang::t('Existing repository found and opened with the password.');
			} elseif (self::repositoryMissing($err)) {
				[$code, , $err] = Restic::run(['init'], 180);
				if ($code !== 0) {
					throw new RuntimeException(Lang::t('restic init: %s', trim($err)));
				}
				$log[] = Lang::t('New encrypted repository created.');
			} else {
				throw new RuntimeException(trim($err) ?: Lang::t('The repository could not be opened (wrong password or no connection).'));
			}
		} catch (\Throwable $e) {
			// Redact while the new environment is still in place.
			$message = Restic::redact($e->getMessage());
			foreach ($previous as $file => $content) {
				if ($content === null) {
					@unlink($file);
				} else {
					file_put_contents($file, $content);
					@chmod($file, 0600);
				}
			}
			throw new RuntimeException($message . "\n" . Lang::t('The previous target was left unchanged.'));
		}

		BackupSettings::set('configured', '1');
		BackupSettings::set('target_type', $type);
		BackupSettings::set('target_label', $target['label']);
		BackupSettings::set('target_details', json_encode($target['details'], JSON_UNESCAPED_UNICODE));
		BackupSettings::set('last_check_at', (string)time());
		BackupSettings::set('last_check_ok', '1');
		JobQueue::create(JobQueue::TYPE_INDEX, 'cron');

		return [true, implode("\n", $log)];
	}

	private static function check(): array
	{
		[$code, , $err] = Restic::run(['cat', 'config'], 120);
		BackupSettings::set('last_check_at', (string)time());
		BackupSettings::set('last_check_ok', $code === 0 ? '1' : '0');

		if ($code !== 0) {
			return [false, Restic::redact(trim($err)) ?: Lang::t('The storage target is not responding.')];
		}

		return [true, Lang::t('The storage target responds and the repository can be opened.')];
	}

	/**
	 * Tells "does not exist yet" from "no access": only in the first case is
	 * a repository created. A wrong password or a connection error must
	 * never lead to a second, empty repository.
	 */
	private static function repositoryMissing(string $err): bool
	{
		if (stripos($err, 'unable to open config file') === false || stripos($err, 'is there a repository') === false) {
			return false;
		}
		// restic also says "Is there a repository" when access is denied or
		// times out; a repository is only created if the file is explicitly missing.
		return preg_match('~does not exist|no such file|not found|NoSuchKey|NoSuchBucket~i', $err) === 1
			&& preg_match('~denied|forbidden|timeout|timed out|refused|unreachable~i', $err) !== 1;
	}
}
