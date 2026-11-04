<?php

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit(1);
}

/**
 * Applies all migrations of the Backup extension.
 *
 *   php migrations/apply.php /var/www/froxlor
 *   php migrations/apply.php /var/www/froxlor --dry-run
 *
 * Connects through PDO with exactly the credentials and connection options
 * Froxlor itself uses (lib/userdata.inc.php). The installation therefore
 * needs neither a MySQL command line client nor a second configuration, and
 * it works wherever Froxlor works.
 *
 * Migrations are numbered PHP files, applied in name order. Each returns a
 * function that receives the connection. All migrations are idempotent; a
 * repeated run is harmless.
 */

require __DIR__ . '/connection.php';

$froxlor = $argv[1] ?? '';
$dryRun = in_array('--dry-run', array_slice($argv, 2), true);

[$pdo, $db, $host, $user] = ext_backup_connect($froxlor);
echo "  Database '$db' on '$host' as '$user'\n";

$files = glob(__DIR__ . '/[0-9]*.php') ?: [];
sort($files);
if (!$files) {
	echo "  No migrations found.\n";
	exit(0);
}

foreach ($files as $file) {
	$name = basename($file);
	if ($dryRun) {
		echo "  would apply: $name\n";
		continue;
	}

	try {
		$migration = require $file;
		if (!is_callable($migration)) {
			throw new RuntimeException('does not return a function');
		}
		$migration($pdo);
	} catch (Throwable $e) {
		fwrite(STDERR, "  ERROR in $name:\n    " . $e->getMessage() . "\n");
		exit(1);
	}
	echo "  applied: $name\n";
}
