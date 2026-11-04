<?php

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit(1);
}

/**
 * Checks the backup schedule - no database needed.
 * Run:  php tests/schedule.php
 */

require __DIR__ . '/../lib/Froxlor/Ext/Backup/Tags.php';
require __DIR__ . '/../lib/Froxlor/Ext/Backup/Schedule.php';

use Froxlor\Ext\Backup\Schedule;

date_default_timezone_set('Europe/Berlin');

$failed = 0;
$check = static function (string $name, bool $ok) use (&$failed): void {
	echo ($ok ? '  ok    ' : '  FAIL  ') . $name . "\n";
	if (!$ok) {
		$failed++;
	}
};
$keys = static fn(array $list): array => array_column($list, 'key');

$times = '00:00,08:00,12:00,18:00';
$at = static fn(string $s): int => strtotime($s);

$check('07:59: nothing due', $keys(Schedule::due($times, '01:00', $at('2026-11-03 07:59'))) === []);
$check('08:00: intermediate backup', $keys(Schedule::due($times, '01:00', $at('2026-11-03 08:00'))) === ['intermediate 2026-11-03 08:00']);
$check('09:30: 08:00 is still caught up', $keys(Schedule::due($times, '01:00', $at('2026-11-03 09:30'))) === ['intermediate 2026-11-03 08:00']);
$check('11:01: 08:00 is too old', $keys(Schedule::due($times, '01:00', $at('2026-11-03 11:01'))) === []);
$check('01:00: nightly backup, 00:00 still in the window',
	$keys(Schedule::due($times, '01:00', $at('2026-11-03 01:00'))) === ['intermediate 2026-11-03 00:00', 'nightly 2026-11-03 01:00']);
$check('nightly backup has run kind nightly', Schedule::due($times, '01:00', $at('2026-11-03 01:05'))[1]['run'] === 'nightly');
$check('window across midnight: 18:00 at 20:59', $keys(Schedule::due($times, '01:00', $at('2026-11-03 20:59'))) === ['intermediate 2026-11-03 18:00']);
$check('yesterday 00:00 no longer counts at 02:30', !in_array('intermediate 2026-11-02 00:00', $keys(Schedule::due($times, '01:00', $at('2026-11-03 02:30'))), true));
$check('invalid time is skipped', $keys(Schedule::due('xx,08:00', '01:00', $at('2026-11-03 08:01'))) === ['intermediate 2026-11-03 08:00']);
$check('key is unique per slot', $keys(Schedule::due($times, '01:00', $at('2026-11-03 08:01'))) === $keys(Schedule::due($times, '01:00', $at('2026-11-03 08:44'))));
$check('runFrom: nightly', Schedule::runFrom('nightly 2026-11-03 01:00') === 'nightly');
$check('runFrom: default intermediate', Schedule::runFrom('intermediate manual') === 'intermediate' && Schedule::runFrom(null) === 'intermediate');

echo $failed === 0 ? "\nAll checks passed.\n" : "\n$failed check(s) failed.\n";
exit($failed === 0 ? 0 : 1);
