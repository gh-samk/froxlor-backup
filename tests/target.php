<?php

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit(1);
}

/**
 * Checks the validation of the storage target - no database, no restic.
 * Run:  php tests/target.php
 */

require __DIR__ . '/../lib/Froxlor/Ext/Backup/Lang.php';
require __DIR__ . '/../lib/Froxlor/Ext/Backup/Target.php';

Froxlor\Ext\Backup\Lang::set('en');

use Froxlor\Ext\Backup\Target;

$failed = 0;
$check = static function (string $name, bool $ok) use (&$failed): void {
	echo ($ok ? '  ok    ' : '  FAIL  ') . $name . "\n";
	if (!$ok) {
		$failed++;
	}
};
$throws = static function (string $type, array $input): bool {
	try {
		Target::validate($type, $input);
		return false;
	} catch (RuntimeException) {
		return true;
	}
};

$s3 = [
	'endpoint' => 's3.example.com',
	'bucket' => 'example-backup',
	'folder' => '/server1/',
	'region' => 'eu-central-1',
	'access_key' => 'AKIAEXAMPLE1234',
	'secret_key' => 'secretSECRET1234+/=',
];
$t = Target::validate('s3', $s3);
$check('S3: repository address', $t['repository'] === 's3:https://s3.example.com/example-backup/server1');
$check('S3: environment has key and region', $t['env']['AWS_ACCESS_KEY_ID'] === 'AKIAEXAMPLE1234' && $t['env']['AWS_DEFAULT_REGION'] === 'eu-central-1');
$check('S3: label contains no secret', !str_contains($t['label'], 'secret') && !str_contains($t['label'], 'AKIA'));
$check('S3: details contain no secret', !in_array('secretSECRET1234+/=', $t['details'], true) && !in_array('AKIAEXAMPLE1234', $t['details'], true));

$check('S3: endpoint with https:// rejected', $throws('s3', ['endpoint' => 'https://x.example'] + $s3));
$check('S3: endpoint with path rejected', $throws('s3', ['endpoint' => 'x.example/evil'] + $s3));
$check('S3: bucket with uppercase letters rejected', $throws('s3', ['bucket' => 'Bucket'] + $s3));
$check('S3: folder with line break rejected', $throws('s3', ['folder' => "a\nRESTIC_PASSWORD=x"] + $s3));
$check('S3: key with line break rejected', $throws('s3', ['access_key' => "AKIAEXAMPLE1234\nX=1"] + $s3));
$check('S3: secret with spaces rejected', $throws('s3', ['secret_key' => 'ab cd efgh ijkl'] + $s3));

$sftp = Target::validate('sftp', ['host' => 'backup-host', 'path' => 'restic-repo']);
$check('SFTP: repository address', $sftp['repository'] === 'sftp:backup-host:restic-repo');
$check('SFTP: host with option rejected', $throws('sftp', ['host' => '-oProxyCommand=x', 'path' => 'a']));
$check('SFTP: path with .. rejected', $throws('sftp', ['host' => 'h', 'path' => '../etc']));
$check('SFTP: path with space rejected', $throws('sftp', ['host' => 'h', 'path' => 'a b']));
$check('Unknown type rejected', $throws('ftp', []));

echo $failed === 0 ? "\nAll checks passed.\n" : "\n$failed check(s) failed.\n";
exit($failed === 0 ? 0 : 1);
