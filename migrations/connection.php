<?php

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit(1);
}

/**
 * Opens a PDO connection with exactly the credentials and options Froxlor
 * itself uses (lib/userdata.inc.php), interpreted by the same code as the
 * extension's runtime (lib/Froxlor/Ext/Backup/DbConfig.php). The file is
 * parsed, never included: it belongs to the panel user, and these helpers
 * usually run as root.
 *
 * Shared by apply.php and remove.php.
 *
 * @return array{0: PDO, 1: string, 2: string, 3: string}  PDO, database, host or socket, user
 */
function ext_backup_connect(string $froxlor): array
{
	require_once dirname(__DIR__) . '/lib/Froxlor/Ext/Backup/DbConfig.php';

	$file = $froxlor . '/lib/userdata.inc.php';
	if ($froxlor === '' || !is_file($file) || !is_readable($file)) {
		fwrite(STDERR, "lib/userdata.inc.php not found or not readable.\n");
		exit(1);
	}

	// Parsed, not executed: the file belongs to the panel user, this runs as root.
	try {
		[$sql] = \Froxlor\Ext\Backup\DbConfig::readUserdata($file);
	} catch (RuntimeException $e) {
		fwrite(STDERR, $e->getMessage() . "\n");
		exit(1);
	}
	if (!isset($sql['db'], $sql['user'])) {
		fwrite(STDERR, "userdata.inc.php contains no usable \$sql configuration.\n");
		exit(1);
	}
	$config = \Froxlor\Ext\Backup\DbConfig::normalize($sql, (string)$sql['db']);

	try {
		$pdo = \Froxlor\Ext\Backup\DbConfig::pdo($config, $config['db'], 'utf8');
	} catch (PDOException $e) {
		fwrite(STDERR, "Connection failed: " . $e->getMessage() . "\n");
		exit(1);
	}

	return [$pdo, $config['db'], $config['socket'] !== '' ? $config['socket'] : $config['host'] . ':' . $config['port'], $config['user']];
}
