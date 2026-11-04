<?php

namespace Froxlor\Ext\Backup;

use Froxlor\Settings;

/**
 * One complete backup run across all customers.
 *
 * Intermediate backup: every customer, nothing more.
 * Nightly backup: additionally the server itself, then the cleanup down to
 * the configured retention and a sample check of the repository.
 *
 * An error with one customer does not stop the others; the run then counts
 * as failed so that it shows up in the history.
 */
class BackupRun
{
	/**
	 * @return array{0:bool, 1:string, 2:bool} success, log, and whether
	 *         there were warnings (e.g. skipped tables) without errors
	 */
	public static function run(string $run): array
	{
		@set_time_limit(0);

		if (!Target::exists()) {
			return [false, Lang::t('ERROR: No storage target has been set up yet.')];
		}

		$froxlor = dirname(__DIR__, 4);
		$snapshotter = new Snapshotter($froxlor);
		// A restic process that died (reboot, OOM) leaves an exclusive lock
		// behind that would make every backup of this run fail.
		$snapshotter->unlockStale();
		$roots = array_values(array_filter([
			(string)Settings::Get('system.documentroot_prefix'),
			(string)Settings::Get('system.vmail_homedir'),
		]));

		$lines = [];
		$customers = Manifest::customers();
		$lines[] = Lang::t('%s: %d customers', $run === Tags::RUN_NIGHTLY ? Lang::t('Nightly backup') : Lang::t('Intermediate backup'), count($customers));

		foreach ($customers as $customer) {
			try {
				$manifest = Manifest::forCustomer($customer);
				$databases = Manifest::databasesOfCustomer((int)$customer['customerid']);
				foreach ($snapshotter->customer($manifest, $databases, $run, $roots) as $line) {
					$lines[] = $line;
				}
			} catch (\Throwable $e) {
				$lines[] = Lang::t('ERROR: %s: %s', $customer['loginname'], Restic::redact($e->getMessage()));
			}
		}

		if ($run === Tags::RUN_NIGHTLY) {
			$steps = [
				Lang::t('System') => static fn() => implode("\n", $snapshotter->system($run)),
				Lang::t('Cleanup') => static fn() => $snapshotter->cleanup((int)BackupSettings::get('retention_days', '7')),
			];
			foreach ($steps as $name => $step) {
				try {
					$lines[] = $step();
				} catch (\Throwable $e) {
					$lines[] = Lang::t('ERROR: %s: %s', $name, Restic::redact($e->getMessage()));
				}
			}

			try {
				$lines[] = $snapshotter->check();
				BackupSettings::set('last_check_ok', '1');
			} catch (\Throwable $e) {
				$lines[] = Lang::t('ERROR: %s', Restic::redact($e->getMessage()));
				BackupSettings::set('last_check_ok', '0');
			}
			BackupSettings::set('last_check_at', (string)time());
		}

		$ok = true;
		foreach ($lines as $line) {
			// The lines are written in the current language; "ERROR" (or its
			// translation) starts every error line.
			if (str_starts_with($line, Lang::t('ERROR'))) {
				$ok = false;
				break;
			}
		}

		$warned = false;
		foreach ($lines as $line) {
			if (str_starts_with($line, Lang::t('WARNING'))) {
				$warned = true;
				break;
			}
		}

		return [$ok, implode("\n", $lines), $warned];
	}
}
