<?php

namespace Froxlor\Ext\Backup;

use Froxlor\Database\Database;
use Froxlor\Settings;
use PDO;
use RuntimeException;

/**
 * Resolves a restore job against Froxlor's current data and runs it through
 * Restorer (or only produces the preview).
 *
 * Job parameters (JSON in the column "subject"):
 *   snapshot     restore point (snapshot ID)
 *   customer     customer (loginname); "_system" for the panel settings
 *   mode         in_place | copy
 *   save_before  1 = save the current state first (default), 0 = do not
 *   parts        list of parts, each with
 *     kind       web | mail | db
 *     domain     domain id (web)        path   relative path (web, mail; empty = all)
 *     mailbox    mail_users.id (mail)
 *     database   database name (db)     table  table (db; empty = all)
 * An undo job has no "parts" and is itself the only part (kind "undo"); a
 * settings job has kind "settings".
 *
 * None of this is used unchecked: customer, database and mailbox are checked
 * against Froxlor's tables, target paths come from Froxlor, never from the
 * job.
 */
class RestoreJob
{
	public const MODE_IN_PLACE = 'in_place';
	public const MODE_COPY = 'copy';

	public const KIND_WEB = 'web';
	public const KIND_MAIL = 'mail';
	public const KIND_DB = 'db';
	public const KIND_UNDO = 'undo';
	public const KIND_SETTINGS = 'settings';

	/** Name of the directory next to the web tree that holds web copies (see webCopyRoot). */
	public const WEB_COPY_DIR = 'restore';

	/**
	 * @return array{0:bool, 1:string} success and log
	 */
	public static function run(array $job, bool $previewOnly): array
	{
		@set_time_limit(0);

		if (!Target::exists()) {
			return [false, Lang::t('ERROR: No storage target has been set up yet.')];
		}
		$p = json_decode((string)$job['subject'], true);
		if (!is_array($p)) {
			return [false, Lang::t('ERROR: The job contains no readable data.')];
		}

		if (($p['kind'] ?? '') === self::KIND_SETTINGS) {
			return self::settings($job, $p, $previewOnly);
		}

		$login = (string)($p['customer'] ?? '');
		$mode = ($p['mode'] ?? self::MODE_IN_PLACE) === self::MODE_COPY ? self::MODE_COPY : self::MODE_IN_PLACE;
		// Without an explicit value (e.g. for an undo) the safety backup stays on.
		$saveBefore = (int)($p['save_before'] ?? 1) !== 0;
		// Several parts in one job ("parts"); a job without a list (undo) is
		// itself the only part.
		$parts = isset($p['parts']) && is_array($p['parts']) ? array_values($p['parts']) : [$p];
		if (count($parts) === 0 || count($parts) > 100) {
			return [false, Lang::t('ERROR: Nothing is selected to restore.')];
		}
		$r = new Restorer(self::froxlorDir());

		$customer = self::customer($login);
		$point = $r->checkPoint((string)($p['snapshot'] ?? ''), $login);
		$time = date('d.m.Y H:i', (int)strtotime($point['time']));
		$stamp = date('Ymd-His');
		$lines = [$previewOnly ? Lang::t('Preview for %s, point from %s', $login, $time) : Lang::t('Restore for %s, point from %s', $login, $time)];

		$ok = true;
		foreach ($parts as $part) {
			try {
				foreach (self::part((array)$part, $customer, $point, $mode, $saveBefore, $stamp, $r, $previewOnly, (int)$job['id']) as $line) {
					$lines[] = $line;
				}
			} catch (\Throwable $e) {
				$ok = false;
				$lines[] = Lang::t('ERROR: %s', Restic::redact($e->getMessage()));
			}
		}

		$lines[] = '';
		if (!$ok) {
			$lines[] = $previewOnly ? Lang::t('At least one part cannot be restored. Please adjust the selection.') : Lang::t('Not everything could be restored. The parts before it are done.');
		} else {
			$lines[] = $previewOnly ? Lang::t('Ready. The restore starts only after confirmation.') : Lang::t('Done.');
		}

		return [$ok, implode("\n", $lines)];
	}

	private static function froxlorDir(): string
	{
		return dirname(__DIR__, 4);
	}

	/**
	 * Restores the panel settings (panel_settings) from the server backup:
	 * only values that differ or are missing.
	 *
	 * Excluded are state values that must not be turned back (versions, last
	 * assigned IDs, timestamps) and this extension's own state. After writing,
	 * the configuration is rebuilt (Froxlor task) so the values take effect.
	 *
	 * @return array{0:bool, 1:string}
	 */
	private static function settings(array $job, array $p, bool $previewOnly): array
	{
		$r = new Restorer(self::froxlorDir());
		$point = $r->checkSystemPoint((string)($p['snapshot'] ?? ''));
		$values = $r->readSettings($point['id'], DbAccess::load(self::froxlorDir(), 0), (int)$job['id']);

		$current = [];
		$stmt = Database::prepare('SELECT `settinggroup`, `varname`, `value` FROM `' . TABLE_PANEL_SETTINGS . '`');
		Database::pexecute($stmt);
		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$current[$row['settinggroup'] . '.' . $row['varname']] = (string)$row['value'];
		}

		$protected = static fn(string $key): bool => str_starts_with($key, BackupSettings::GROUP . '.')
			|| preg_match('~(^|\.)(version|dbversion|db_version|lastguid|lastcronrun|last[a-z_]*|.*_lastrun|.*_version)$~i', $key) === 1;

		$changes = [];
		foreach ($values as $key => $old) {
			if ($protected($key)) {
				continue;
			}
			if (!array_key_exists($key, $current) || $current[$key] !== $old) {
				$changes[$key] = ['old' => $old, 'current' => $current[$key] ?? null];
			}
		}

		$time = date('d.m.Y H:i', (int)strtotime($point['time']));
		$lines = [$previewOnly ? Lang::t('Preview of the server settings, point from %s', $time) : Lang::t('Restore of the server settings, point from %s', $time), ''];
		if (count($changes) === 0) {
			return [true, implode("\n", $lines) . Lang::t('The settings are identical to the state of the backup. There is nothing to restore.')];
		}

		$short = static fn(?string $v): string => $v === null ? Lang::t('(missing)') : (strlen($v) > 60 ? substr($v, 0, 57) . '...' : ($v === '' ? Lang::t('(empty)') : $v));
		$secret = static fn(string $k): bool => (bool)preg_match('~(passw|secret|key|token)~i', $k);
		foreach ($changes as $k => $c) {
			$lines[] = $k . ': ' . ($secret($k) ? Lang::t('(value hidden)') : $short($c['current']) . '  ->  ' . $short($c['old']));
		}
		$lines[] = '';

		if ($previewOnly) {
			$lines[] = Lang::t('%d setting(s) would be set to the state of the backup.', count($changes));
			$lines[] = Lang::t('The current settings are backed up first. Then Froxlor rewrites the server configuration.');
			$lines[] = Lang::t('Ready. The restore starts only after confirmation.');

			return [true, implode("\n", $lines)];
		}

		// Save the current state before anything is written.
		$before = Snapshotter::BASE_DIR . '/' . Restorer::DB_SUBDIR . '/before-settings-' . (int)$job['id'];
		$access = DbAccess::load(self::froxlorDir(), null);
		SecureDir::ensure(dirname($before), 0700);
		if (is_link($before) || !@mkdir($before, 0700)) {
			throw new RuntimeException(Lang::t('Could not create %s.', $before));
		}
		try {
			$access->dump($access->defaultDatabase(), 'panel_settings', $before . '/panel_settings.sql.gz', $before);
			[$code, , $err] = Restic::run(['backup', '--quiet',
				'--tag', Tags::type(Tags::TYPE_PRE_RESTORE), '--tag', Tags::customer(Tags::SYSTEM_CUSTOMER), '--tag', Tags::run(Tags::RUN_RESTORE),
				'--', $before], 3600, true);
			if ($code !== 0) {
				throw new RuntimeException(Lang::t('The current state could not be backed up; nothing was changed: %s', trim(Restic::redact($err))));
			}
		} finally {
			@unlink($before . '/panel_settings.sql.gz');
			@rmdir($before);
		}
		$lines[] = Lang::t('Current state backed up (restore point "pre-restore").');

		$upd = Database::prepare('UPDATE `' . TABLE_PANEL_SETTINGS . '` SET `value` = :v WHERE `settinggroup` = :g AND `varname` = :n');
		$ins = Database::prepare('INSERT INTO `' . TABLE_PANEL_SETTINGS . '` (`settinggroup`, `varname`, `value`) VALUES (:g, :n, :v)');
		foreach ($changes as $k => $c) {
			[$g, $n] = explode('.', $k, 2);
			if ($c['current'] === null) {
				Database::pexecute($ins, ['g' => $g, 'n' => $n, 'v' => $c['old']]);
			} else {
				Database::pexecute($upd, ['v' => $c['old'], 'g' => $g, 'n' => $n]);
			}
		}

		// Froxlor task "rebuild configuration files" (type 1), processed by the cron.
		$task = Database::prepare('INSERT INTO `' . TABLE_PANEL_TASKS . '` (`type`, `data`) VALUES (1, :d)');
		Database::pexecute($task, ['d' => '']);
		$lines[] = Lang::t('%d setting(s) reset, configuration is being rewritten.', count($changes));
		$lines[] = Lang::t('Done.');

		return [true, implode("\n", $lines)];
	}

	/**
	 * One part of the job: web files (of one domain), one mailbox or one
	 * database, each with a heading.
	 *
	 * @return string[]
	 */
	private static function part(array $p, array $customer, array $point, string $mode, bool $saveBefore, string $stamp, Restorer $r, bool $previewOnly, int $jobId): array
	{
		$login = (string)$customer['loginname'];
		$kind = (string)($p['kind'] ?? '');

		if ($kind === self::KIND_DB) {
			$db = (string)($p['database'] ?? '');
			$table = ($p['table'] ?? '') === '' ? null : (string)$p['table'];
			$server = self::databaseServer($customer, $db);
			$lines = [self::heading($table !== null ? Lang::t('Database %s, table %s', $db, $table) : Lang::t('Database %s', $db))];
			$names = $r->databasePreview($point['id'], $login, $db, $table);
			if ($previewOnly) {
				$lines[] = Lang::t('%d table(s): %s', count($names), implode(', ', array_slice($names, 0, 15))) . (count($names) > 15 ? ' ...' : '');
				$lines[] = $mode === self::MODE_IN_PLACE
					? ($saveBefore ? Lang::t('The current state is backed up first; the tables are replaced.') : Lang::t('The tables are replaced; the current state is NOT backed up.'))
					: Lang::t('The copy goes into database %s_restore; the originals remain untouched.', $db);
			} else {
				$access = DbAccess::load(self::froxlorDir(), $server);
				foreach ($r->restoreDatabase($point['id'], $login, $db, $table, $mode, $saveBefore, $access, $jobId) as $line) {
					$lines[] = $line;
				}
			}

			return $lines;
		}

		if (!in_array($kind, [self::KIND_WEB, self::KIND_MAIL, self::KIND_UNDO], true)) {
			throw new RuntimeException(Lang::t('Unknown restore type: %s', $kind));
		}

		if ($kind === self::KIND_MAIL && in_array(Tags::WITHOUT_MAIL, $point['tags'], true)) {
			throw new RuntimeException(Lang::t('This point contains no mailboxes (intermediate backup). Please choose a nightly backup.'));
		}

		$target = self::fileTarget($kind, $p, $customer, $point, $stamp, $mode);
		$lines = [self::heading($target['title'])];
		if (!empty($target['web_copy'])) {
			self::assertNotServed($target['root']);
			if (!$previewOnly) {
				self::prepareWebCopyRoot($target['root'], $target['uid'], $target['gid']);
			}
		}
		if ($previewOnly) {
			$c = $r->contents($point['id'], $target['source']);
			$lines[] = Lang::t('%d files in %d folders (%s)', $c['files'], $c['folders'], self::size($c['bytes']));
			$lines[] = file_exists($target['target']) ? Lang::t('Target: %s (exists, will be overwritten)', $target['target']) : Lang::t('Target: %s (does not currently exist)', $target['target']);
			if ($mode === self::MODE_IN_PLACE) {
				$lines[] = $saveBefore
					? Lang::t('The current state is backed up first; files that exist only today are kept.')
					: Lang::t('The current state is NOT backed up; files that exist only today are kept.');
			}
		} else {
			foreach ($r->restoreFiles([
				'id' => $point['id'], 'login' => $login, 'source' => $target['source'], 'target' => $target['target'],
				'uid' => $target['uid'], 'gid' => $target['gid'], 'mode' => $mode, 'save_before' => $saveBefore, 'root' => $target['root'], 'job' => $jobId,
			]) as $line) {
				$lines[] = $line;
			}
			if ($target['mail']) {
				$lines[] = self::dovecotResync($target['mail_user'] ?? null);
			}
		}

		return $lines;
	}

	private static function heading(string $text): string
	{
		return "\n" . $text;
	}

	/**
	 * Source (in the point), target (on the server), user and allowed root.
	 *
	 * @return array{title:string, source:string, target:string, uid:int, gid:int, root:string, mail:bool, mail_user?:string, web_copy?:bool}
	 */
	private static function fileTarget(string $kind, array $p, array $customer, array $point, string $stamp, string $mode): array
	{
		$prefix = (string)Settings::Get('system.documentroot_prefix');
		$vmail = (string)Settings::Get('system.vmail_homedir');

		if ($kind === self::KIND_UNDO) {
			// A "pre-restore" point knows the path it saved.
			if (!in_array(Tags::type(Tags::TYPE_PRE_RESTORE), $point['tags'], true) || count($point['paths']) !== 1) {
				throw new RuntimeException(Lang::t('This is not a point for undoing.'));
			}
			$path = $point['paths'][0];
			$web = realpath((string)$customer['documentroot']);
			$mailRoot = realpath($vmail . '/' . $customer['loginname']);
			foreach ([[$web, false], [$mailRoot, true]] as [$root, $isMail]) {
				if ($root !== false && ($path === $root || str_starts_with($path, $root . '/'))) {
					self::underRoot($root, $prefix, $vmail, $isMail);
					[$uid, $gid] = $isMail ? self::mailOwner($customer, $path) : [(int)$customer['guid'], (int)$customer['guid']];

					return ['title' => Lang::t('Restore state: %s', $path), 'source' => $path, 'target' => $path, 'uid' => $uid, 'gid' => $gid, 'root' => $root, 'mail' => $isMail];
				}
			}
			throw new RuntimeException(Lang::t('The backed-up path %s no longer belongs to this customer.', $path));
		}

		$rel = Restorer::relativePath((string)($p['path'] ?? ''));
		$suffix = $rel === '' ? '' : '/' . $rel;

		if ($kind === self::KIND_WEB) {
			$web = realpath((string)$customer['documentroot']);
			if ($web === false) {
				throw new RuntimeException(Lang::t('The customer\'s web directory does not exist.'));
			}
			self::underRoot($web, $prefix, $vmail, false);

			// A single domain: its document root from Froxlor, which must lie
			// inside the customer's web directory.
			$base = $web;
			$candidates = [rtrim((string)$customer['documentroot'], '/'), $web];
			$name = '';
			if (!empty($p['domain'])) {
				$stmt = Database::prepare('SELECT `domain`, `documentroot` FROM `' . TABLE_PANEL_DOMAINS . '` WHERE `id` = :i AND `customerid` = :c');
				$dom = Database::pexecute_first($stmt, ['i' => (int)$p['domain'], 'c' => (int)$customer['customerid']]);
				if (!$dom) {
					throw new RuntimeException(Lang::t('This domain does not belong to this customer.'));
				}
				$dr = realpath((string)$dom['documentroot']);
				if ($dr === false || !($dr === $web || str_starts_with($dr . '/', $web . '/'))) {
					throw new RuntimeException(Lang::t('The directory of %s does not exist or is outside the customer directory.', $dom['domain']));
				}
				$base = $dr;
				$candidates = [rtrim((string)$dom['documentroot'], '/'), $dr];
				$name = (string)$dom['domain'];
			}
			$sourceBase = self::pointPath($point, $candidates);

			return [
				'title' => $rel !== ''
						? Lang::t('Website %s, %s', $name !== '' ? $name : Lang::t('(all of the customer\'s files)'), $rel)
						: Lang::t('Website %s', $name !== '' ? $name : Lang::t('(all of the customer\'s files)')),
				'source' => $sourceBase . $suffix,
				// Copies never go below the web directory: the customer directory is
				// the document root of the standard subdomain (and of subdomains
				// without a path of their own), so restored files such as
				// wp-config.php would be served over HTTP.
				'target' => $mode === self::MODE_COPY
					? self::webCopyRoot((string)$customer['loginname']) . '/' . $stamp . ($name !== '' ? '/' . $name : '') . $suffix
					: $base . $suffix,
				'uid' => (int)$customer['guid'], 'gid' => (int)$customer['guid'],
				'root' => $mode === self::MODE_COPY ? self::webCopyRoot((string)$customer['loginname']) : $web,
				'mail' => false, 'web_copy' => $mode === self::MODE_COPY,
			];
		}

		// Mailbox
		$stmt = Database::prepare('SELECT `email`, `homedir`, `maildir`, `uid`, `gid` FROM `' . TABLE_MAIL_USERS . '` WHERE `id` = :i AND `customerid` = :c');
		$box = Database::pexecute_first($stmt, ['i' => (int)($p['mailbox'] ?? 0), 'c' => (int)$customer['customerid']]);
		if (!$box) {
			throw new RuntimeException(Lang::t('This mailbox does not belong to this customer.'));
		}
		$mailRoot = realpath($vmail . '/' . $customer['loginname']);
		$boxPath = rtrim($box['homedir'], '/') . '/' . trim($box['maildir'], '/');
		$real = realpath($boxPath);
		if ($mailRoot === false) {
			throw new RuntimeException(Lang::t('There are no backed-up mails for this mailbox: the customer\'s mail directory does not exist (it is created with the first mail).'));
		}
		self::underRoot($mailRoot, $prefix, $vmail, true);
		// The mailbox may be missing today (deleted): then the computed path applies.
		$boxReal = $real !== false ? $real : $boxPath;
		if (!str_starts_with($boxReal . '/', $mailRoot . '/')) {
			throw new RuntimeException(Lang::t('The mailbox is outside the customer\'s mail directory.'));
		}
		$base = self::pointPath($point, [$boxReal, rtrim($boxPath, '/')]);

		return [
			'title' => $rel !== '' ? Lang::t('Mailbox %s, %s', $box['email'], $rel) : Lang::t('Mailbox %s', $box['email']),
			'source' => $base . $suffix,
			'target' => $mode === self::MODE_COPY
				? $mailRoot . '/' . Restorer::COPY_DIR . '/' . $stamp . '/' . preg_replace('~[^A-Za-z0-9._@-]~', '_', (string)$box['email']) . $suffix
				: $boxReal . $suffix,
			'uid' => (int)$box['uid'], 'gid' => (int)$box['gid'], 'root' => $mailRoot, 'mail' => true,
			'mail_user' => (string)$box['email'],
		];
	}

	/**
	 * Where web copies of a customer go: /var/customers/restore/<login> for
	 * the default prefix /var/customers/webs - next to the web tree, outside
	 * every document root. Reachable for the admin (and anyone the admin
	 * gives the files to), not for a web server.
	 */
	public static function webCopyRoot(string $login): string
	{
		$prefix = rtrim((string)Settings::Get('system.documentroot_prefix'), '/');
		$parent = dirname($prefix !== '' ? $prefix : '/var/customers/webs');

		return rtrim($parent, '/') . '/' . self::WEB_COPY_DIR . '/' . $login;
	}

	/**
	 * Refuses a copy location that is a document root, lies inside one, or
	 * contains one - of any domain or customer in Froxlor.
	 */
	public static function assertNotServed(string $copyRoot): void
	{
		$copy = realpath($copyRoot) ?: rtrim($copyRoot, '/');
		$roots = [];
		$stmt = Database::prepare('SELECT `documentroot` FROM `' . TABLE_PANEL_DOMAINS . '` UNION SELECT `documentroot` FROM `' . TABLE_PANEL_CUSTOMERS . '`');
		Database::pexecute($stmt);
		foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $dr) {
			$dr = trim((string)$dr);
			if ($dr !== '' && !preg_match('~^[a-z][a-z0-9+.-]*://~i', $dr)) { // redirect domains carry a URL
				$roots[] = realpath($dr) ?: rtrim($dr, '/');
			}
		}
		foreach (array_unique($roots) as $root) {
			if ($root === '' || $root === '/') {
				continue;
			}
			if ($copy === $root || str_starts_with($copy . '/', $root . '/') || str_starts_with($root . '/', $copy . '/')) {
				throw new RuntimeException(Lang::t('The copy location %s lies inside or around the website directory %s - aborted.', $copy, $root));
			}
		}
	}

	/**
	 * Creates the customer's copy directory: below a root-owned parent (0711),
	 * owned by the customer, 0700. The parent belongs to root, so the customer
	 * cannot swap the directory for a link.
	 */
	private static function prepareWebCopyRoot(string $dir, int $uid, int $gid): void
	{
		SecureDir::ensure(dirname($dir), 0711);
		if (is_link($dir)) {
			throw new RuntimeException(Lang::t('%s is a symbolic link - aborted.', $dir));
		}
		if (!is_dir($dir)) {
			if (!@mkdir($dir, 0700)) {
				throw new RuntimeException(Lang::t('Could not create %s.', $dir));
			}
			if (!@lchown($dir, $uid) || !@lchgrp($dir, $gid)) {
				throw new RuntimeException(Lang::t('Could not create %s.', $dir));
			}
		}
		$stat = lstat($dir);
		if ($stat === false || ($stat['mode'] & 0170000) !== 0040000 || $stat['uid'] !== $uid) {
			throw new RuntimeException(Lang::t('%s does not belong to the customer - aborted.', $dir));
		}
		@chmod($dir, 0700);
	}

	/**
	 * Of several possible paths, the one that was backed up as a root of the
	 * point or lies below one.
	 */
	private static function pointPath(array $point, array $candidates): string
	{
		foreach ($candidates as $c) {
			foreach ($point['paths'] as $p) {
				if ($c === $p || str_starts_with($c . '/', rtrim($p, '/') . '/')) {
					return $c;
				}
			}
		}
		throw new RuntimeException(Lang::t('This point does not contain the path %s (backed-up paths: %s).', $candidates[0], implode(', ', $point['paths'])));
	}

	/**
	 * A customer root must really lie below the web or mail area; otherwise a
	 * wrongly set documentroot (/, /etc) would become the target.
	 */
	private static function underRoot(string $root, string $webPrefix, string $mailPrefix, bool $mail): void
	{
		$allowed = realpath($mail ? $mailPrefix : $webPrefix);
		if ($allowed === false || !str_starts_with($root . '/', rtrim($allowed, '/') . '/') || $root === $allowed) {
			throw new RuntimeException(Lang::t('The directory %s is not under %s.', $root, $mail ? $mailPrefix : $webPrefix));
		}
	}

	/**
	 * Owner of a mailbox for the undo case: from Froxlor, found by its path;
	 * otherwise the system's vmail user.
	 *
	 * @return array{0:int, 1:int}
	 */
	private static function mailOwner(array $customer, string $path): array
	{
		$stmt = Database::prepare('SELECT `homedir`, `maildir`, `uid`, `gid` FROM `' . TABLE_MAIL_USERS . '` WHERE `customerid` = :c');
		Database::pexecute($stmt, ['c' => (int)$customer['customerid']]);
		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$box = rtrim($row['homedir'], '/') . '/' . trim($row['maildir'], '/');
			if ($path === $box || str_starts_with($path, $box . '/')) {
				return [(int)$row['uid'], (int)$row['gid']];
			}
		}

		return [(int)Settings::Get('system.vmail_uid'), (int)Settings::Get('system.vmail_gid')];
	}

	private static function customer(string $login): array
	{
		if (!preg_match('~^[A-Za-z0-9_-]{1,50}$~', $login)) {
			throw new RuntimeException(Lang::t('Invalid customer name.'));
		}
		$stmt = Database::prepare('SELECT `customerid`, `loginname`, `documentroot`, `guid` FROM `' . TABLE_PANEL_CUSTOMERS . '` WHERE `loginname` = :l');
		$c = Database::pexecute_first($stmt, ['l' => $login]);
		if (!$c) {
			throw new RuntimeException(Lang::t('Customer %s does not (or no longer) exist in Froxlor. Re-creating customers is not part of this version.', $login));
		}

		return $c;
	}

	private static function databaseServer(array $customer, string $db): int
	{
		$stmt = Database::prepare('SELECT `dbserver` FROM `' . TABLE_PANEL_DATABASES . '` WHERE `customerid` = :c AND `databasename` = :d');
		$row = Database::pexecute_first($stmt, ['c' => (int)$customer['customerid'], 'd' => $db]);
		if (!$row) {
			throw new RuntimeException(Lang::t('Database %s does not belong to this customer.', $db));
		}

		return (int)$row['dbserver'];
	}

	/**
	 * Dovecot re-reads the folders so restored mails appear immediately. If
	 * doveadm is missing or fails, that is only a note: Dovecot catches up on
	 * the next access anyway.
	 */
	private static function dovecotResync(?string $address): string
	{
		if ($address === null || !is_executable('/usr/bin/doveadm') || !preg_match('~^[A-Za-z0-9._%+@-]+$~', $address)) {
			return Lang::t('Note: Dovecot was not triggered; the mails will appear on the next fetch.');
		}
		$p = @proc_open(['/usr/bin/doveadm', 'force-resync', '-u', $address, '*'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, '/', ['PATH' => '/usr/bin:/bin']);
		if (!is_resource($p)) {
			return Lang::t('Note: doveadm could not be started.');
		}
		stream_get_contents($pipes[1]);
		stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);

		return proc_close($p) === 0 ? Lang::t('Dovecot has re-read the mailbox.') : Lang::t('Note: doveadm force-resync reported an error.');
	}

	private static function size(int $bytes): string
	{
		foreach (['B', 'KB', 'MB', 'GB'] as $i => $unit) {
			if ($bytes < 1024 ** ($i + 1) || $unit === 'GB') {
				return round($bytes / 1024 ** $i, $i > 1 ? 1 : 0) . ' ' . $unit;
			}
		}

		return $bytes . ' B';
	}
}
