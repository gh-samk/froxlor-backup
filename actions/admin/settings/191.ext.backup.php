<?php

/**
 * Settings of the Backup extension.
 *
 * The storage target is deliberately not here: credentials do not belong in
 * the panel database. They are handed to the cron once under Backup › Setup
 * and afterwards live only in /etc/froxlor-backup (root).
 */

use Froxlor\Ext\Backup\Lang;

// Loaded by Froxlor; never meant to be requested directly.
if (PHP_SAPI !== 'cli' && realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
	http_response_code(404);
	exit(1);
}

return [
	'groups' => [
		'ext_backup' => [
			'title' => Lang::t('Backup'),
			'icon' => 'fa-solid fa-cloud-arrow-up',
			'fields' => [
				'ext_backup_intermediate_times' => [
					'label' => [
						'title' => Lang::t('Intermediate backups per day'),
						'description' => Lang::t('Times as HH:MM, separated by commas, server time. Each backup is a restore point; thanks to deduplication it only costs what has changed.'),
					],
					'settinggroup' => 'ext_backup',
					'varname' => 'intermediate_times',
					'type' => 'text',
					'string_regexp' => '/^([01]\d|2[0-3]):[0-5]\d(,([01]\d|2[0-3]):[0-5]\d){0,7}$/',
					'default' => '00:00,08:00,12:00,18:00',
					'save_method' => 'storeSettingField',
				],
				'ext_backup_nightly_time' => [
					'label' => [
						'title' => Lang::t('Nightly backup'),
						'description' => Lang::t('Time as HH:MM. The nightly backup is the thorough one: it also covers the panel database and server configuration, checks the repository and cleans up old restore points.'),
					],
					'settinggroup' => 'ext_backup',
					'varname' => 'nightly_time',
					'type' => 'text',
					'string_regexp' => '/^([01]\d|2[0-3]):[0-5]\d$/',
					'default' => '01:00',
					'save_method' => 'storeSettingField',
				],
				'ext_backup_mail_nightly_only' => [
					'label' => [
						'title' => Lang::t('Mailboxes only in the nightly backup'),
						'description' => Lang::t('Websites and databases are included in every backup, mailboxes only at night. Every backup goes through all emails; for large mailboxes this saves time and load. Storage use hardly changes. Trade-off: a mailbox can only be restored to the state of the last nightly backup.'),
					],
					'settinggroup' => 'ext_backup',
					'varname' => 'mail_nightly_only',
					'type' => 'checkbox',
					'default' => true,
					'save_method' => 'storeSettingField',
				],
				'ext_backup_retention_days' => [
					'label' => [
						'title' => Lang::t('Retention in days'),
						'description' => Lang::t('All restore points of the last days are kept, older ones are deleted during the nightly backup. If the bucket uses Object Lock, match the lock period to this.'),
					],
					'settinggroup' => 'ext_backup',
					'varname' => 'retention_days',
					'type' => 'number',
					'min' => 1,
					'max' => 365,
					'default' => 7,
					'save_method' => 'storeSettingField',
				],
			],
		],
	],
];
