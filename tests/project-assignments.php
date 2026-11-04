<?php

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit(1);
}

/**
 * Checks the optional consumer side of the resource-assignment hook
 * (ProjectAssignments) with stub providers - no database, no Project View.
 * Run:  php tests/project-assignments.php
 *
 * Each scenario runs in a child process, because a stub class, once
 * declared, cannot be replaced.
 */

$scenario = $argv[1] ?? null;

if ($scenario !== null) {
	require __DIR__ . '/../lib/Froxlor/Ext/Backup/ProjectAssignments.php';
	$stub = static function (string $body): void {
		eval('namespace Froxlor\\Ext\\ProjectView; final class BackupIntegration { public static function resourceAssignments(int $customerId): mixed { ' . $body . ' } }');
	};
	switch ($scenario) {
		case 'absent':
			break;
		case 'valid':
			$stub('return $customerId === 7 ? [
				["domainid" => 3, "type" => "database", "id" => 11],
				["domainid" => "4", "type" => "ftp", "id" => "12"],
			] : [];');
			break;
		case 'malformed':
			$stub('return [
				["domainid" => 3, "type" => "database", "id" => 11],
				"not an array",
				["domainid" => 0, "type" => "database", "id" => 13],
				["domainid" => -1, "type" => "database", "id" => 14],
				["domainid" => 5, "type" => "mailbox", "id" => 15],
				["domainid" => 5, "type" => "database"],
				["domainid" => "5; DROP", "type" => "database", "id" => 16],
				["domainid" => 5.0, "type" => "database", "id" => 17],
				["domainid" => [5], "type" => "database", "id" => 18],
				["domainid" => 6, "type" => "DATABASE", "id" => 19],
			];');
			break;
		case 'not-array':
			$stub('return "nope";');
			break;
		case 'throws':
			$stub('throw new \\RuntimeException("database gone");');
			break;
		case 'error':
			$stub('return \\undefined_function_xyz();');
			break;
		case 'no-method':
			eval('namespace Froxlor\\Ext\\ProjectView; final class BackupIntegration { }');
			break;
	}
	echo json_encode([
		'all' => Froxlor\Ext\Backup\ProjectAssignments::forCustomer(7),
		'db' => Froxlor\Ext\Backup\ProjectAssignments::databaseDomains(7),
	]);
	exit(0);
}

$failed = 0;
$check = static function (string $name, bool $ok, string $info = '') use (&$failed): void {
	echo ($ok ? '  ok    ' : '  FAIL  ') . $name . ($ok || $info === '' ? '' : "\n        " . $info) . "\n";
	if (!$ok) {
		$failed++;
	}
};
$run = static function (string $scenario): ?array {
	$out = shell_exec(escapeshellarg(PHP_BINARY) . ' -d error_reporting=-1 -d display_errors=stderr ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg($scenario) . ' 2>&1');
	$data = json_decode((string)$out, true);

	return is_array($data) ? $data : ['raw' => $out];
};

$r = $run('absent');
$check('no provider: empty, nothing breaks', $r === ['all' => [], 'db' => []], json_encode($r));

$r = $run('valid');
$check('valid provider: entries passed through, digit strings converted', $r['all'] === [
	['domainid' => 3, 'type' => 'database', 'id' => 11],
	['domainid' => 4, 'type' => 'ftp', 'id' => 12],
], json_encode($r));
$check('valid provider: database id => domain id', $r['db'] === ['11' => 3], json_encode($r['db']));

$r = $run('malformed');
$check('malformed entries are ignored, the valid one stays', $r['all'] === [['domainid' => 3, 'type' => 'database', 'id' => 11]], json_encode($r));

foreach (['not-array' => 'non-array result', 'throws' => 'provider throws', 'error' => 'provider has a fatal error', 'no-method' => 'class without the method'] as $s => $name) {
	$r = $run($s);
	$check("$name: behaves as if not installed", $r === ['all' => [], 'db' => []], json_encode($r));
}

echo $failed === 0 ? "\nAll checks passed.\n" : "\n$failed check(s) failed.\n";
exit($failed === 0 ? 0 : 1);
