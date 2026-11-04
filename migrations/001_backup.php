<?php

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit(1);
}

/**
 * Backup: job queue, snapshot index, settings and cron job.
 *
 * Idempotent; safe to run any number of times.
 *
 * Called by migrations/apply.php with its connection.
 */

return static function (PDO $pdo): void {
	// The panel only creates rows here; they are executed by the cron as
	// root. "secret_params" may hold credentials once (setup); the cron
	// clears the column the moment it takes over the job.
	$pdo->exec("
		CREATE TABLE IF NOT EXISTS `ext_backup_jobs` (
		  `id`            int unsigned NOT NULL AUTO_INCREMENT,
		  `type`          varchar(32)  NOT NULL,
		  `status`        varchar(16)  NOT NULL DEFAULT 'queued',
		  `subject`       text NULL,
		  `secret_params` mediumtext NULL,
		  `created_by`    varchar(255) NOT NULL DEFAULT '',
		  `created_at`    int NOT NULL DEFAULT 0,
		  `started_at`    int NOT NULL DEFAULT 0,
		  `finished_at`   int NOT NULL DEFAULT 0,
		  `log`           mediumtext NULL,
		  PRIMARY KEY (`id`),
		  KEY `status` (`status`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
	");

	// Cache of the restic snapshots. The interface only reads here; the panel
	// never talks to restic itself.
	$pdo->exec("
		CREATE TABLE IF NOT EXISTS `ext_backup_index` (
		  `snapshot_id`   varchar(64)  NOT NULL,
		  `snapshot_time` int          NOT NULL DEFAULT 0,
		  `host`          varchar(255) NOT NULL DEFAULT '',
		  `type`          varchar(32)  NULL,
		  `customer`      varchar(64)  NULL,
		  `tags`          text NULL,
		  `paths`         text NULL,
		  PRIMARY KEY (`snapshot_id`),
		  KEY `snapshot_time` (`snapshot_time`),
		  KEY `customer` (`customer`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
	");

	// Every minute, so a requested job starts quickly. The cron itself also
	// triggers the hourly index refresh and the scheduled backups.
	$pdo->prepare("
		INSERT INTO `cronjobs_run` (`module`, `cronfile`, `cronclass`, `interval`, `isactive`, `desc_lng_key`)
		SELECT ?, ?, ?, ?, 1, ? FROM DUAL
		WHERE NOT EXISTS (SELECT 1 FROM `cronjobs_run` WHERE `cronfile` = ?)
	")->execute([
		'ext/backup',
		'ext_backup_jobs',
		'\\Froxlor\\Ext\\Backup\\Cron\\BackupJobCron',
		'1 MINUTE',
		'Backup: run jobs',
		'ext_backup_jobs',
	]);

	// Froxlor rewrites /etc/cron.d/froxlor only for task 99. Without it the
	// new cron job would stay unknown to the system cron until some other
	// change triggers the rewrite.
	$pdo->exec("
		INSERT INTO `panel_tasks` (`type`, `data`)
		SELECT 99, '' FROM DUAL
		WHERE NOT EXISTS (SELECT 1 FROM `panel_tasks` WHERE `type` = 99)
	");

	$settings = [
		'configured' => '0',
		'target_type' => '',
		'target_label' => '',
		'target_details' => '',
		'intermediate_times' => '00:00,08:00,12:00,18:00',
		'nightly_time' => '01:00',
		'retention_days' => '7',
		'mail_nightly_only' => '1',
		'index_updated_at' => '0',
		'last_check_at' => '0',
		'last_check_ok' => '0',
	];
	$stmt = $pdo->prepare("
		INSERT INTO `panel_settings` (`settinggroup`, `varname`, `value`)
		SELECT 'ext_backup', ?, ? FROM DUAL
		WHERE NOT EXISTS (SELECT 1 FROM `panel_settings` WHERE `settinggroup` = 'ext_backup' AND `varname` = ?)
	");
	foreach ($settings as $varname => $value) {
		$stmt->execute([$varname, $value, $varname]);
	}
};
