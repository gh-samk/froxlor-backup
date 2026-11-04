<?php

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit(1);
}

/**
 * Removes the traces of the Backup extension from the database.
 *
 *   php migrations/remove.php /var/www/froxlor           unregister the cron job
 *   php migrations/remove.php /var/www/froxlor --purge   additionally drop tables and settings
 *
 * Always, even without --purge, the cron job is unregistered: otherwise
 * Froxlor's scheduler would keep calling a class that no longer exists.
 *
 * Never touched: /etc/froxlor-backup (credentials and the encryption
 * password - without the password the backups in the storage cannot be
 * opened), the repository itself and /var/lib/froxlor-backup.
 */

require __DIR__ . '/connection.php';

$froxlor = $argv[1] ?? '';
$purge = in_array('--purge', array_slice($argv, 2), true);

[$pdo, $db, $host, $user] = ext_backup_connect($froxlor);
echo "  Database '$db' on '$host' as '$user'\n";

$removed = $pdo->exec("DELETE FROM `cronjobs_run` WHERE `module` = 'ext/backup'");
if ($removed > 0) {
	// Let Froxlor rewrite /etc/cron.d/froxlor without the job.
	$pdo->exec("
		INSERT INTO `panel_tasks` (`type`, `data`)
		SELECT 99, '' FROM DUAL
		WHERE NOT EXISTS (SELECT 1 FROM `panel_tasks` WHERE `type` = 99)
	");
}
echo "  Cron job unregistered\n";

if (!$purge) {
	echo "  Tables kept (use --purge to delete them as well)\n";
	exit(0);
}

foreach (['ext_backup_jobs', 'ext_backup_index'] as $table) {
	$pdo->exec("DROP TABLE IF EXISTS `" . $table . "`");
	echo "  dropped: $table\n";
}
$pdo->exec("DELETE FROM `panel_settings` WHERE `settinggroup` = 'ext_backup'");
echo "  Settings deleted\n";
