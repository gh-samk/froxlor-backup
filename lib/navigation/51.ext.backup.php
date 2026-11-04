<?php

/**
 * Menu entry "Backup".
 *
 * Purely additive: Froxlor merges every file in lib/navigation/. The backup
 * concerns the whole server (one repository for all customers), so only
 * admins with the right to change server settings see the entry. The page
 * checks this a second time.
 */

use Froxlor\Ext\Backup\Lang;

// Loaded by Froxlor; never meant to be requested directly.
if (PHP_SAPI !== 'cli' && realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
	http_response_code(404);
	exit(1);
}

return [
	'admin' => [
		'ext_backup' => [
			'label' => Lang::t('Backup'),
			'required_resources' => 'change_serversettings',
			'icon' => 'fa-solid fa-cloud-arrow-up',
			'elements' => [
				[
					'url' => 'admin_backup.php?page=overview',
					'label' => Lang::t('Overview'),
					'required_resources' => 'change_serversettings',
				],
				[
					'url' => 'admin_backup.php?page=restore',
					'label' => Lang::t('Restore'),
					'required_resources' => 'change_serversettings',
				],
				[
					'url' => 'admin_backup.php?page=history',
					'label' => Lang::t('History'),
					'required_resources' => 'change_serversettings',
				],
				[
					'url' => 'admin_backup.php?page=setup',
					'label' => Lang::t('Setup'),
					'required_resources' => 'change_serversettings',
				],
			],
		],
	],
];
