<?php

namespace Froxlor\Ext\Backup;

/**
 * Which backup is due when.
 *
 * Pure calculation without a database, so it can be tested
 * (tests/schedule.php). A backup is due once its time has been reached and
 * is no older than the catch-up window: if the cron hangs or an earlier
 * backup runs long, it is caught up, but never one that is hours old.
 */
class Schedule
{
	/** A backup is still caught up this many seconds after its planned time. */
	public const WINDOW = 10800;

	/**
	 * @param string $times   intermediate backups, "HH:MM" separated by commas
	 * @param string $nightly nightly backup, "HH:MM"
	 * @return array<int,array{key:string, run:string, time:int}> ascending by time;
	 *         the key is unique per appointment ("intermediate 2026-11-03 08:00")
	 */
	public static function due(string $times, string $nightly, int $now): array
	{
		$slots = [];
		foreach (array_filter(array_map('trim', explode(',', $times))) as $clock) {
			$slots[] = [Tags::RUN_INTERMEDIATE, $clock];
		}
		$slots[] = [Tags::RUN_NIGHTLY, trim($nightly)];

		$due = [];
		// Check yesterday as well: a window can reach past midnight.
		foreach ([-1, 0] as $days) {
			$day = strtotime(date('Y-m-d', $now) . ' ' . $days . ' day');
			foreach ($slots as [$run, $clock]) {
				if (!preg_match('~^([01]\d|2[0-3]):([0-5]\d)$~', $clock, $t)) {
					continue;
				}
				$time = mktime((int)$t[1], (int)$t[2], 0, (int)date('n', $day), (int)date('j', $day), (int)date('Y', $day));
				if ($time <= $now && $now - $time < self::WINDOW) {
					$due[] = [
						'key' => $run . ' ' . date('Y-m-d H:i', $time),
						'run' => $run,
						'time' => $time,
					];
				}
			}
		}
		usort($due, static fn($a, $b) => $a['time'] <=> $b['time']);

		return $due;
	}

	/**
	 * The run kind is part of the job subject ("nightly 2026-11-03 01:00");
	 * anything unknown counts as the lighter intermediate backup.
	 */
	public static function runFrom(?string $subject): string
	{
		return str_starts_with((string)$subject, Tags::RUN_NIGHTLY) ? Tags::RUN_NIGHTLY : Tags::RUN_INTERMEDIATE;
	}
}
