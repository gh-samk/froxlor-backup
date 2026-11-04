<?php

/**
 * Backup for Froxlor 2.x
 *
 * Overview, restore, history and setup of the storage target. Backups and
 * restores are done with restic; this page only creates jobs and shows the
 * cache (ext_backup_index). Everything that touches credentials or customer
 * data runs in the cron as root (lib/Froxlor/Ext/Backup/Cron/BackupJobCron.php).
 *
 * Purely additive: Froxlor does not know this file and therefore never
 * overwrites it during an update.
 */

const AREA = 'admin';
require __DIR__ . '/lib/init.php';

use Froxlor\CurrentUser;
use Froxlor\Database\Database;
use Froxlor\Ext\Backup\BackupSettings;
use Froxlor\Ext\Backup\JobQueue;
use Froxlor\Ext\Backup\Lang;
use Froxlor\Ext\Backup\ProjectAssignments;
use Froxlor\Ext\Backup\ProjectViewIntegration;
use Froxlor\Ext\Backup\Restic;
use Froxlor\Ext\Backup\RestoreJob;
use Froxlor\Ext\Backup\Restorer;
use Froxlor\Ext\Backup\SnapshotIndex;
use Froxlor\Ext\Backup\Tags;
use Froxlor\Ext\Backup\Target;
use Froxlor\FroxlorLogger;
use Froxlor\UI\Panel\UI;
use Froxlor\UI\Request;
use Froxlor\UI\Response;

// One repository for the whole server: admins without the right to change
// server settings have no business here (the menu hides the entry for them
// as well).
if ((int)($userinfo['change_serversettings'] ?? 0) !== 1) {
	Response::dynamicError(Lang::t('Only users who may change the server settings can manage the backup.'));
}

/**
 * Renders a template from templates/ExtBackup/.
 *
 * Rendered directly instead of through UI::view(): Froxlor looks up every
 * page template in the active theme first, and this extension's templates
 * are not part of any theme.
 */
function backup_view(string $template, array $context = []): void
{
	$context['style_version'] = (int)@filemtime(__DIR__ . '/templates/ExtBackup/style.css');
	try {
		Lang::registerTwig(UI::twig());
		echo UI::twig()->render('ExtBackup/' . $template, $context);
	} catch (Exception $e) {
		Response::dynamicError(htmlspecialchars($e->getMessage()));
	}
}

/**
 * Error page. Froxlor prints the message unescaped, so it is escaped here;
 * and it stores the posted form in the session to refill it, so secrets
 * (access keys, encryption password) are removed from it first.
 */
function backup_error(string $message): void
{
	unset($_POST['password'], $_POST['s3']['access_key'], $_POST['s3']['secret_key']);
	Response::dynamicError(htmlspecialchars($message));
}

function backup_notice(string $text, string $type = 'success'): void
{
	CurrentUser::setField('ext_backup_notice', ['type' => $type, 'text' => $text]);
}

/**
 * "3 h ago" instead of a timestamp; 0 means "never".
 */
function backup_age(int $time): string
{
	if ($time <= 0) {
		return Lang::t('never');
	}
	$d = max(0, time() - $time);
	if ($d < 90) {
		return Lang::t('just now');
	}
	if ($d < 5400) {
		return Lang::t('%d min ago', round($d / 60));
	}
	if ($d < 129600) {
		return Lang::t('%d h ago', round($d / 3600));
	}

	return Lang::t('%d days ago', round($d / 86400));
}

/**
 * "Today, 13:48", "Yesterday, 18:00" or "03.11.2026, 13:48".
 */
function backup_point_time(int $time): string
{
	$day = date('Y-m-d', $time);
	if ($day === date('Y-m-d')) {
		return Lang::t('Today, %s', date('H:i', $time));
	}
	if ($day === date('Y-m-d', strtotime('-1 day'))) {
		return Lang::t('Yesterday, %s', date('H:i', $time));
	}

	return Lang::t('%s, %s', date('d.m.Y', $time), date('H:i', $time));
}

/**
 * Summary of the parts of a job: "2 websites, 1 mailbox, 1 database".
 */
function backup_parts_summary(array $p): string
{
	if (($p['kind'] ?? '') === RestoreJob::KIND_UNDO) {
		return Lang::t('Restore an earlier version');
	}
	$n = [RestoreJob::KIND_WEB => 0, RestoreJob::KIND_MAIL => 0, RestoreJob::KIND_DB => 0];
	foreach ((array)($p['parts'] ?? []) as $part) {
		if (isset($n[$part['kind'] ?? ''])) {
			$n[$part['kind']]++;
		}
	}
	$parts = [];
	if ($n[RestoreJob::KIND_WEB]) {
		$parts[] = $n[RestoreJob::KIND_WEB] === 1 ? Lang::t('1 website') : Lang::t('%d websites', $n[RestoreJob::KIND_WEB]);
	}
	if ($n[RestoreJob::KIND_MAIL]) {
		$parts[] = $n[RestoreJob::KIND_MAIL] === 1 ? Lang::t('1 mailbox') : Lang::t('%d mailboxes', $n[RestoreJob::KIND_MAIL]);
	}
	if ($n[RestoreJob::KIND_DB]) {
		$parts[] = $n[RestoreJob::KIND_DB] === 1 ? Lang::t('1 database') : Lang::t('%d databases', $n[RestoreJob::KIND_DB]);
	}

	return implode(', ', $parts);
}

/**
 * The customer from Froxlor; stops if it does not exist.
 */
function backup_customer(string $login): array
{
	if (!preg_match('~^[A-Za-z0-9_-]{1,50}$~', $login)) {
		backup_error(Lang::t('Invalid customer name.'));
	}
	$stmt = Database::prepare('SELECT `customerid`, `loginname`, `name`, `firstname`, `company` FROM `' . TABLE_PANEL_CUSTOMERS . '` WHERE `loginname` = :l');
	$c = Database::pexecute_first($stmt, ['l' => $login]);
	if (!$c) {
		backup_error(Lang::t('This customer no longer exists in Froxlor. Recreating deleted customers will follow later.'));
	}

	return $c;
}

$page = (string)Request::any('page');
if (!in_array($page, ['overview', 'history', 'setup', 'restore'], true)) {
	$page = 'overview';
}

$by = (string)($userinfo['loginname'] ?? 'admin');

/*
 * Actions. Each one creates at most one job and returns via
 * POST-redirect-GET, so a reload repeats nothing.
 */
if (Request::post('send') == 'send') {
	$action = (string)Request::post('action');

	if ($action === 'check' || $action === 'index' || $action === 'backup') {
		// A backup started by hand is always the light kind; the time in the
		// subject keeps it apart from the scheduled ones.
		$typeOf = ['check' => JobQueue::TYPE_CHECK, 'index' => JobQueue::TYPE_INDEX, 'backup' => JobQueue::TYPE_BACKUP];
		$created = JobQueue::create($typeOf[$action], $by, null, $action === 'backup' ? Tags::RUN_INTERMEDIATE . ' manual ' . date('Y-m-d H:i') : null);
		backup_notice($created
			? Lang::t('The job starts with the next cron run, usually within a minute.')
			: Lang::t('This job is already waiting or running.'), $created ? 'success' : 'info');
		$log->logAction(FroxlorLogger::ADM_ACTION, LOG_NOTICE, 'Backup: job ' . $action . ' created');
		Response::redirectTo($filename, ['page' => $page]);
	}

	if ($action === 'preview' || $action === 'restore' || $action === 'undo') {
		$login = (string)Request::post('customer');
		$system = $login === Tags::SYSTEM_CUSTOMER; // server settings instead of a customer
		$customerRow = $system ? [] : backup_customer($login);
		$back = $system ? ['page' => 'restore', 'view' => 'settings'] : ['page' => 'restore', 'customer' => $login];

		if ($system && $action === 'preview') {
			$point = (string)Request::post('snapshot');
			if (!SnapshotIndex::hasSystemPoint($point)) {
				backup_error(Lang::t('This restore point does not belong to the server backup.'));
			}
			$params = ['snapshot' => $point, 'customer' => Tags::SYSTEM_CUSTOMER, 'kind' => RestoreJob::KIND_SETTINGS, 'mode' => RestoreJob::MODE_IN_PLACE];
			$created = JobQueue::create(JobQueue::TYPE_PREVIEW, $by, null, json_encode($params));
			backup_notice($created
				? Lang::t('The preview is being created (one minute at most). The confirmation will then appear here.')
				: Lang::t('Another preview is still waiting or running. Please wait a moment.'), $created ? 'success' : 'info');
			Response::redirectTo($filename, $back);
		}
		if ($system && $action === 'undo') {
			backup_error(Lang::t('The server settings cannot be undone from the interface.'));
		}

		if ($action === 'preview') {
			$point = (string)Request::post('snapshot');
			if (!SnapshotIndex::hasPoint($point, $login)) {
				backup_error(Lang::t('This restore point does not belong to this customer.'));
			}
			$cid = (int)$customerRow['customerid'];
			$parts = [];
			$list = static fn(string $field): array => array_values(array_unique(array_filter((array)Request::post($field, []), 'is_scalar')));

			$webs = $list('web');
			$mails = $list('mail');
			$dbs = $list('db');
			$path = '';
			$table = '';
			try {
				// Folder and table only apply if exactly one part of that kind is selected.
				if (count($webs) === 1) {
					$path = Restorer::relativePath((string)Request::post('path'));
				}
			} catch (RuntimeException $e) {
				backup_error($e->getMessage());
			}
			if (count($dbs) === 1) {
				$table = trim((string)Request::post('table'));
				if ($table !== '' && !preg_match('~^[^\x00-\x1f`]{1,64}$~u', $table)) {
					backup_error(Lang::t('The table name contains invalid characters.'));
				}
			}

			foreach ($webs as $domainId) {
				$stmt = Database::prepare('SELECT `id` FROM `' . TABLE_PANEL_DOMAINS . '` WHERE `id` = :i AND `customerid` = :c');
				if (!Database::pexecute_first($stmt, ['i' => (int)$domainId, 'c' => $cid])) {
					backup_error(Lang::t('This domain does not belong to this customer.'));
				}
				$parts[] = ['kind' => RestoreJob::KIND_WEB, 'domain' => (int)$domainId, 'path' => $path];
			}
			foreach ($mails as $mailboxId) {
				$stmt = Database::prepare('SELECT `id` FROM `' . TABLE_MAIL_USERS . '` WHERE `id` = :i AND `customerid` = :c');
				if (!Database::pexecute_first($stmt, ['i' => (int)$mailboxId, 'c' => $cid])) {
					backup_error(Lang::t('This mailbox does not belong to this customer.'));
				}
				$parts[] = ['kind' => RestoreJob::KIND_MAIL, 'mailbox' => (int)$mailboxId, 'path' => ''];
			}
			foreach ($dbs as $name) {
				$stmt = Database::prepare('SELECT `databasename` FROM `' . TABLE_PANEL_DATABASES . '` WHERE `customerid` = :c AND `databasename` = :d');
				if (!Database::pexecute_first($stmt, ['c' => $cid, 'd' => (string)$name])) {
					backup_error(Lang::t('This database does not belong to this customer.'));
				}
				$parts[] = ['kind' => RestoreJob::KIND_DB, 'database' => (string)$name, 'table' => $table];
			}
			if (count($parts) === 0) {
				backup_error(Lang::t('Please select at least one item to restore.'));
			}
			if (count($parts) > 100) {
				backup_error(Lang::t('That is too many items at once (100 at most).'));
			}

			$params = [
				'snapshot' => $point,
				'customer' => $login,
				'mode' => Request::post('mode') === RestoreJob::MODE_COPY ? RestoreJob::MODE_COPY : RestoreJob::MODE_IN_PLACE,
				'save_before' => Request::post('save_before') === '1' ? 1 : 0,
				'parts' => $parts,
			];
			$created = JobQueue::create(JobQueue::TYPE_PREVIEW, $by, null, json_encode($params, JSON_UNESCAPED_UNICODE));
			backup_notice($created
				? Lang::t('The preview is being created (one minute at most). The confirmation will then appear here.')
				: Lang::t('Another preview is still waiting or running. Please wait a moment.'), $created ? 'success' : 'info');
			Response::redirectTo($filename, $back);
		}

		// From here on things are overwritten or brought back: typed
		// confirmation with the customer name, checked on the server.
		$params = null;
		if ($action === 'restore') {
			$job = JobQueue::find((int)Request::post('job'));
			if (!$job || $job['type'] !== JobQueue::TYPE_PREVIEW || $job['status'] !== JobQueue::STATUS_DONE || ($job['params']['customer'] ?? '') !== $login) {
				backup_error(Lang::t('This preview is no longer valid.'));
			}
			$params = $job['params'];
		} else {
			$point = (string)Request::post('snapshot');
			if (!SnapshotIndex::hasPoint($point, $login)) {
				backup_error(Lang::t('This restore point does not belong to this customer.'));
			}
			$params = ['snapshot' => $point, 'customer' => $login, 'kind' => RestoreJob::KIND_UNDO, 'mode' => RestoreJob::MODE_IN_PLACE];
		}

		if (($params['mode'] ?? RestoreJob::MODE_IN_PLACE) === RestoreJob::MODE_IN_PLACE) {
			$expected = $system ? Lang::t('settings') : $login;
			$input = trim((string)Request::post('confirmation'));
			if ($input === '' || mb_strtolower($input) !== mb_strtolower($expected)) {
				backup_error(Lang::t('The confirmation does not match “%s”. Nothing was changed.', $expected));
			}
		}

		$created = JobQueue::create(JobQueue::TYPE_RESTORE, $by, null, json_encode($params, JSON_UNESCAPED_UNICODE));
		$log->logAction(FroxlorLogger::ADM_ACTION, LOG_WARNING, 'Backup: restore for ' . $login . ' created (' . ($params['kind'] ?? 'parts') . ', ' . ($params['mode'] ?? '?') . ')');
		backup_notice($created
			? Lang::t('The restore starts with the next cron run. The result will appear here.')
			: Lang::t('A restore is still waiting or running. Please wait for it to finish.'), $created ? 'success' : 'info');
		Response::redirectTo($filename, $back);
	}

	if ($action === 'setup') {
		$type = Request::post('use_sftp') === '1' ? 'sftp' : 's3';
		$input = (array)Request::post($type, []);

		// Same validation as in the cron, so typing errors show up immediately.
		try {
			Target::validate($type, $input);
		} catch (RuntimeException $e) {
			backup_error($e->getMessage());
		}

		if (BackupSettings::get('configured', '0') === '1' && Request::post('replace') !== '1') {
			backup_error(Lang::t('A storage target is already set up. To replace it, please tick the checkbox.'));
		}

		$password = trim((string)Request::post('password'));
		$generated = false;
		$keep = $password === '' && Request::post('keep_password') === '1';
		if ($keep) {
			// Only change credentials or location and keep the repository
			// with its current password; the cron reads it on the server.
			$password = '';
		} elseif ($password === '') {
			// 36 random bytes as URL-safe Base64: 48 characters without
			// special characters that cause trouble in password files.
			$password = rtrim(strtr(base64_encode(random_bytes(36)), '+/', '-_'), '=');
			$generated = true;
		} elseif (strlen($password) < 12 || preg_match('~[\r\n\0]~', $password)) {
			backup_error(Lang::t('The encryption password must be at least 12 characters long and must not contain line breaks.'));
		}

		$created = JobQueue::create(JobQueue::TYPE_SETUP, $by, [
			'type' => $type,
			'target' => $input,
			'password' => $password,
			'keep_password' => $keep,
		]);
		if (!$created) {
			backup_error(Lang::t('The setup is already running. Please wait for the result in the history.'));
		}

		// The generated password is shown exactly once: without it the backups
		// cannot be opened, and there is no way to recover it.
		CurrentUser::setField('ext_backup_generated_password', $generated ? $password : null);
		backup_notice(Lang::t('The setup starts with the next cron run. The result will then appear in the history.'));
		$log->logAction(FroxlorLogger::ADM_ACTION, LOG_NOTICE, 'Backup: storage target is being set up (' . ($type === 's3' ? 'S3' : 'SFTP') . ')');
		Response::redirectTo($filename, ['page' => 'history']);
	}

	if ($action === 'remove') {
		if (BackupSettings::get('configured', '0') !== '1') {
			backup_error(Lang::t('No storage target is set up.'));
		}
		$input = trim((string)Request::post('confirmation'));
		if (mb_strtolower($input) !== mb_strtolower(Lang::t('remove')) || Request::post('password_saved') !== '1') {
			backup_error(Lang::t('Please type “%s” and confirm that the encryption password is stored elsewhere. Nothing was changed.', Lang::t('remove')));
		}
		$created = JobQueue::create(JobQueue::TYPE_REMOVE, $by);
		backup_notice($created
			? Lang::t('The storage target will be removed with the next cron run. The result will appear in the history.')
			: Lang::t('The removal is already running.'), $created ? 'success' : 'info');
		$log->logAction(FroxlorLogger::ADM_ACTION, LOG_WARNING, 'Backup: storage target is being removed');
		Response::redirectTo($filename, ['page' => 'history']);
	}

	backup_error(Lang::t('Unknown action.'));
}

$notice = CurrentUser::getField('ext_backup_notice');
CurrentUser::setField('ext_backup_notice', null);

$generatedPassword = null;
if ($page === 'history') {
	$generatedPassword = CurrentUser::getField('ext_backup_generated_password');
	CurrentUser::setField('ext_backup_generated_password', null);
}

$stats = SnapshotIndex::stats();
$configured = BackupSettings::get('configured', '0') === '1';
// A backup counts as overdue after 26 hours: daily plus slack.
$stale = $configured && ($stats['newest'] === 0 || time() - $stats['newest'] > ProjectViewIntegration::STALE_AFTER);

$log->logAction(FroxlorLogger::ADM_ACTION, LOG_NOTICE, 'viewed admin_backup');

$restore = ['customers' => [], 'customer' => null, 'system' => null];
if ($page === 'restore') {
	$login = (string)Request::any('customer');
	if ((string)Request::any('view') === 'settings') {
		$jobs = JobQueue::forCustomer(Tags::SYSTEM_CUSTOMER, 6);
		foreach ($jobs as $i => &$j) {
			$j['confirmable'] = $i === 0 && $j['type'] === JobQueue::TYPE_PREVIEW && $j['status'] === JobQueue::STATUS_DONE;
		}
		unset($j);
		$restore['system'] = [
			'points' => array_map(static function (array $p): array {
				$p['label'] = backup_point_time((int)$p['snapshot_time']) . ' · ' . $p['kind'];

				return $p;
			}, SnapshotIndex::systemPoints()),
			'jobs' => $jobs,
		];
	} elseif ($login === '') {
		$restore['customers'] = SnapshotIndex::customers();
	} else {
		$c = backup_customer($login);
		$cid = (int)$c['customerid'];

		// Arranged by project: a main domain with its subdomains, the
		// mailboxes of these domains and the databases assigned to it.
		$stmt = Database::prepare('SELECT `standardsubdomain` FROM `' . TABLE_PANEL_CUSTOMERS . '` WHERE `customerid` = :c');
		$std = (int)(Database::pexecute_first($stmt, ['c' => $cid])['standardsubdomain'] ?? 0);
		$stmt = Database::prepare('SELECT `id`, `domain`, `parentdomainid` FROM `' . TABLE_PANEL_DOMAINS . '` WHERE `customerid` = :c ORDER BY `domain`');
		Database::pexecute($stmt, ['c' => $cid]);
		$projects = [];
		$children = [];
		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
			if ((int)$d['id'] === $std) {
				continue; // the customer's standard subdomain: not a project
			}
			// Helper subdomains for mail clients have no website of their own.
			if (preg_match('~^(autoconfig|autodiscover|mail|webmail)\.~', $d['domain']) && (int)$d['parentdomainid'] !== 0) {
				continue;
			}
			if ((int)$d['parentdomainid'] === 0) {
				$projects[(int)$d['id']] = ['id' => (int)$d['id'], 'name' => $d['domain'], 'websites' => [['id' => (int)$d['id'], 'name' => $d['domain'], 'main' => true]], 'mailboxes' => [], 'databases' => []];
			} else {
				$children[(int)$d['parentdomainid']][] = ['id' => (int)$d['id'], 'name' => $d['domain'], 'main' => false];
			}
		}
		$domainToProject = [];
		foreach ($projects as $pid => &$pr) {
			foreach ($children[$pid] ?? [] as $child) {
				$pr['websites'][] = $child;
			}
			foreach ($pr['websites'] as $site) {
				$domainToProject[$site['id']] = $pid;
			}
		}
		unset($pr);

		$unassigned = ['mailboxes' => [], 'databases' => []];
		$stmt = Database::prepare('SELECT `id`, `email`, `domainid` FROM `' . TABLE_MAIL_USERS . '` WHERE `customerid` = :c ORDER BY `email`');
		Database::pexecute($stmt, ['c' => $cid]);
		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
			$pid = $domainToProject[(int)$m['domainid']] ?? null;
			$entry = ['id' => (int)$m['id'], 'email' => $m['email']];
			if ($pid !== null) {
				$projects[$pid]['mailboxes'][] = $entry;
			} else {
				$unassigned['mailboxes'][] = $entry;
			}
		}
		// Froxlor does not link databases to domains; the assignment comes
		// from Project View if it is installed (see ProjectAssignments).
		$dbDomains = ProjectAssignments::databaseDomains($cid);
		$stmt = Database::prepare('SELECT `id`, `databasename` FROM `' . TABLE_PANEL_DATABASES . '` WHERE `customerid` = :c ORDER BY `databasename`');
		Database::pexecute($stmt, ['c' => $cid]);
		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
			$pid = $domainToProject[$dbDomains[(int)$d['id']] ?? 0] ?? null;
			if ($pid !== null) {
				$projects[$pid]['databases'][] = $d['databasename'];
			} else {
				$unassigned['databases'][] = $d['databasename'];
			}
		}

		$points = array_map(static function (array $p): array {
			$p['label'] = backup_point_time((int)$p['snapshot_time']) . ' · ' . $p['kind'];

			return $p;
		}, SnapshotIndex::pointsFor($login, Tags::TYPE_CUSTOMER));
		$undo = array_map(static function (array $p): array {
			$p['label'] = backup_point_time((int)$p['snapshot_time']);

			return $p;
		}, array_values(array_filter(
			SnapshotIndex::pointsFor($login, Tags::TYPE_PRE_RESTORE, 10),
			// Only file states can be brought back, not database dumps.
			static fn(array $p): bool => count($p['paths']) === 1 && !str_contains($p['paths'][0], '/' . Restorer::DB_SUBDIR . '/')
		)));

		$restore['customer'] = [
			'login' => $login,
			'name' => trim(($c['company'] ?: trim($c['firstname'] . ' ' . $c['name']))),
			'points' => $points,
			'undo' => $undo,
			'projects' => array_values($projects),
			'unassigned' => $unassigned,
			'jobs' => JobQueue::forCustomer($login, 8),
		];
		// Only the newest finished preview can be confirmed; older ones
		// describe a state that may have changed long since.
		foreach ($restore['customer']['jobs'] as $i => &$j) {
			$j['summary'] = backup_parts_summary($j['params']);
			$j['confirmable'] = $i === 0 && $j['type'] === JobQueue::TYPE_PREVIEW && $j['status'] === JobQueue::STATUS_DONE;
		}
		unset($j);
	}
}

$covered = [];
if ($page === 'overview' && $configured) {
	$newest = array_column(SnapshotIndex::customers(), 'newest', 'customer');
	$stmt = Database::prepare('
		SELECT `c`.`loginname`, `c`.`company`, `c`.`firstname`, `c`.`name`,
		  (SELECT COUNT(*) FROM `' . TABLE_PANEL_DOMAINS . '` `d` WHERE `d`.`customerid` = `c`.`customerid` AND `d`.`parentdomainid` = 0 AND `d`.`id` <> `c`.`standardsubdomain`) AS `projects`,
		  (SELECT COUNT(*) FROM `' . TABLE_MAIL_USERS . '` `m` WHERE `m`.`customerid` = `c`.`customerid`) AS `mailboxes`,
		  (SELECT COUNT(*) FROM `' . TABLE_PANEL_DATABASES . '` `b` WHERE `b`.`customerid` = `c`.`customerid`) AS `databases`
		FROM `' . TABLE_PANEL_CUSTOMERS . '` `c` ORDER BY `c`.`loginname`');
	Database::pexecute($stmt);
	foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
		$covered[] = [
			'login' => $r['loginname'],
			'name' => trim($r['company'] ?: trim($r['firstname'] . ' ' . $r['name'])),
			'projects' => (int)$r['projects'],
			'mailboxes' => (int)$r['mailboxes'],
			'databases' => (int)$r['databases'],
			'point' => isset($newest[$r['loginname']]) ? backup_point_time((int)$newest[$r['loginname']]) : null,
		];
	}
}

// Preselected from the project page: which project and which part.
$preselect = ['project' => (int)Request::any('project'), 'scope' => (string)Request::any('scope')];

// The configured target without secrets.
$targetDetails = json_decode(BackupSettings::get('target_details'), true);
if (!is_array($targetDetails)) {
	$targetDetails = [];
}

backup_view('page.html.twig', [
	'target_details' => $targetDetails,
	'preselect' => $preselect,
	'covered' => $covered,
	'restore' => $restore,
	'current_page' => $page,
	'notice' => is_array($notice) ? $notice : null,
	'generated_password' => is_string($generatedPassword) && $generatedPassword !== '' ? $generatedPassword : null,
	'configured' => $configured,
	'target_label' => BackupSettings::get('target_label'),
	'check_ok' => BackupSettings::get('last_check_ok', '0') === '1',
	'check_age' => backup_age((int)BackupSettings::get('last_check_at', '0')),
	'stats' => $stats,
	'newest_age' => backup_age($stats['newest']),
	'stale' => $stale,
	'restic_installed' => Restic::installed(),
	'snapshots' => $page === 'overview' ? SnapshotIndex::latest(15) : [],
	'jobs' => $page === 'history' ? JobQueue::history(30) : [],
	'target_types' => Target::types(),
	'retention_days' => (int)BackupSettings::get('retention_days', '7'),
	'times' => str_replace(',', ', ', BackupSettings::get('intermediate_times', '00:00,08:00,12:00,18:00')),
	'nightly_time' => BackupSettings::get('nightly_time', '01:00'),
]);
