<?php

namespace Froxlor\Ext\Backup;

use RuntimeException;

/**
 * Brings files and database tables back from a restore point.
 *
 * Like Snapshotter, this class does not know Froxlor's database; it receives
 * fully resolved paths, users and names (RestoreJob).
 *
 * Principles:
 *  - Before anything is overwritten, the current state is saved as a
 *    "pre-restore" snapshot. If that fails, nothing is overwritten.
 *  - restic, running as root, only writes into a directory of its own. The
 *    copy into the customer's directories is done by the customer
 *    (setpriv), never by root: a planted symlink therefore cannot make root
 *    overwrite foreign files.
 *  - Files are only added and overwritten. Files that exist only in the
 *    current state are kept.
 */
class Restorer
{
	/** Directory created in the customer's mail directory for "restore as a copy" of a mailbox (web copies: RestoreJob::webCopyRoot). */
	public const COPY_DIR = '_restored';

	/** Below BASE_DIR: restic's restore target for files (handed to the customer). */
	public const FILES_SUBDIR = 'restore';

	/** Below BASE_DIR: database dumps during a restore, root only. */
	public const DB_SUBDIR = 'db-restore';

	private const TIMEOUT = 21600;

	private string $froxlor;
	private string $base;

	public function __construct(string $froxlor, string $base = Snapshotter::BASE_DIR)
	{
		$this->froxlor = $froxlor;
		$this->base = rtrim($base, '/');
	}

	/**
	 * Checks that the point exists and belongs to this customer.
	 *
	 * @return array{id:string, tags:string[], paths:string[], time:string}
	 */
	public function checkPoint(string $id, string $login): array
	{
		if (!preg_match('~^[0-9a-f]{8,64}$~', $id)) {
			throw new RuntimeException(Lang::t('Invalid snapshot ID.'));
		}
		[$code, $out, $err] = Restic::run(['snapshots', '--json', $id], 300);
		$list = json_decode($out, true);
		if ($code !== 0 || !is_array($list) || count($list) !== 1) {
			throw new RuntimeException(Lang::t('Restore point %s was not found. %s', $id, trim(Restic::redact($err))));
		}
		$s = $list[0];
		$tags = (array)($s['tags'] ?? []);
		if (!in_array(Tags::customer($login), $tags, true)
			|| !(in_array(Tags::type(Tags::TYPE_CUSTOMER), $tags, true) || in_array(Tags::type(Tags::TYPE_PRE_RESTORE), $tags, true))) {
			throw new RuntimeException(Lang::t('The restore point does not belong to this customer.'));
		}

		return ['id' => (string)$s['id'], 'tags' => $tags, 'paths' => (array)($s['paths'] ?? []), 'time' => (string)($s['time'] ?? '')];
	}

	/**
	 * Checks that the point is a system point (server, panel database).
	 *
	 * @return array{id:string, tags:string[], paths:string[], time:string}
	 */
	public function checkSystemPoint(string $id): array
	{
		if (!preg_match('~^[0-9a-f]{8,64}$~', $id)) {
			throw new RuntimeException(Lang::t('Invalid snapshot ID.'));
		}
		[$code, $out, $err] = Restic::run(['snapshots', '--json', $id], 300);
		$list = json_decode($out, true);
		if ($code !== 0 || !is_array($list) || count($list) !== 1 || !in_array(Tags::type(Tags::TYPE_SYSTEM), (array)($list[0]['tags'] ?? []), true)) {
			throw new RuntimeException(Lang::t('This point is not a backup of the server. %s', trim(Restic::redact($err))));
		}

		return ['id' => (string)$list[0]['id'], 'tags' => (array)$list[0]['tags'], 'paths' => (array)($list[0]['paths'] ?? []), 'time' => (string)($list[0]['time'] ?? '')];
	}

	/**
	 * The settings (panel_settings) from a system point. The dump is loaded
	 * into a short-lived database, read and dropped again; the real panel
	 * database is not touched.
	 *
	 * @param DbAccess $root access with the privilege to create databases
	 * @return array<string,string> "group.name" => value
	 */
	public function readSettings(string $id, DbAccess $root, int $job): array
	{
		$work = $this->base . '/' . self::DB_SUBDIR . '/settings-' . $job;
		$this->fresh($work);
		$tmp = 'froxlor_restore_' . $job;
		$pdo = null;
		try {
			$source = $this->stagingDir() . '/' . Snapshotter::SYSTEM_SUBDIR . '/' . Snapshotter::PANEL_DB_SUBDIR . '/panel_settings.sql.gz';
			[$code, , $err] = Restic::run(['restore', '--quiet', $id, '--target', $work, '--include', self::pattern($source)], self::TIMEOUT, true);
			if ($code !== 0 || !is_file($work . $source)) {
				throw new RuntimeException(Lang::t('The settings are not included in this point. %s', trim(Restic::redact($err))));
			}

			$pdo = $root->pdo();
			$pdo->exec('DROP DATABASE IF EXISTS `' . $tmp . '`');
			$pdo->exec('CREATE DATABASE `' . $tmp . '` CHARACTER SET utf8mb4');
			$root->import($tmp, $work . $source, $work);

			$values = [];
			foreach ($root->pdo($tmp)->query('SELECT `settinggroup`, `varname`, `value` FROM `panel_settings`') as $r) {
				$values[$r['settinggroup'] . '.' . $r['varname']] = (string)$r['value'];
			}

			return $values;
		} finally {
			if ($pdo !== null) {
				$pdo->exec('DROP DATABASE IF EXISTS `' . $tmp . '`');
			}
			$this->remove($work);
		}
	}

	/**
	 * A relative path inside the web directory or mailbox, as the admin
	 * types it. Empty means "everything".
	 */
	public static function relativePath(string $path): string
	{
		$path = trim(str_replace('\\', '/', $path), "/ \t");
		if ($path === '') {
			return '';
		}
		if (preg_match('~[\x00-\x1f]~', $path)) {
			throw new RuntimeException(Lang::t('The path contains control characters.'));
		}
		foreach (explode('/', $path) as $part) {
			if ($part === '..' || $part === '.' || $part === '') {
				throw new RuntimeException(Lang::t('The path must not contain "..", "." or empty parts.'));
			}
		}

		return $path;
	}

	/**
	 * What would be restored? Counts files and size in the point.
	 *
	 * @return array{files:int, folders:int, bytes:int}
	 */
	public function contents(string $id, string $path): array
	{
		[$code, $out, $err] = Restic::run(['ls', '--json', '--recursive', $id, $path], 900);
		if ($code !== 0) {
			throw new RuntimeException(Lang::t('restic ls: %s', trim(Restic::redact($err))));
		}

		$files = $folders = $bytes = 0;
		foreach (preg_split('~\R~', $out, -1, PREG_SPLIT_NO_EMPTY) as $line) {
			$n = json_decode($line, true);
			if (!is_array($n) || ($n['struct_type'] ?? '') !== 'node') {
				continue;
			}
			if (($n['type'] ?? '') === 'dir') {
				$folders++;
			} else {
				$files++;
				$bytes += (int)($n['size'] ?? 0);
			}
		}
		if ($files + $folders === 0) {
			throw new RuntimeException(Lang::t('This path does not exist in the restore point: %s', $path));
		}

		return ['files' => $files, 'folders' => $folders, 'bytes' => $bytes];
	}

	/**
	 * Reads a small file (the manifest) directly from the point.
	 */
	public function file(string $id, string $path): string
	{
		[$code, $out, $err] = Restic::run(['dump', $id, $path], 300);
		if ($code !== 0) {
			throw new RuntimeException(Lang::t('restic dump %s: %s', $path, trim(Restic::redact($err))));
		}

		return $out;
	}

	/**
	 * The customer's manifest from a customer point (not from "pre-restore").
	 */
	public function manifest(string $id, string $login): array
	{
		$m = json_decode($this->file($id, $this->customerStaging($login) . '/manifest.json'), true);
		if (!is_array($m)) {
			throw new RuntimeException(Lang::t('The manifest in the restore point is not readable.'));
		}

		return $m;
	}

	/**
	 * Restores files or a folder.
	 *
	 * @param array{
	 *   id:string, login:string, source:string, target:string, uid:int, gid:int,
	 *   mode:string, save_before?:bool, root:string, job:int
	 * } $a source = absolute path in the point, target = absolute path on the
	 *       server, root = directory the target must be in
	 * @return string[] log lines
	 */
	public function restoreFiles(array $a): array
	{
		if ($a['uid'] < 1000 || $a['gid'] < 1000) {
			throw new RuntimeException(Lang::t('User ID %d is too small for a restore as customer.', $a['uid']));
		}
		foreach (['setpriv', 'timeout'] as $program) {
			if (!is_executable('/usr/bin/' . $program)) {
				throw new RuntimeException(Lang::t('%s is missing (package util-linux or coreutils).', '/usr/bin/' . $program));
			}
		}
		$this->checkTarget($a['target'], $a['root']);

		$lines = [];
		if ($a['mode'] === RestoreJob::MODE_IN_PLACE && ($a['save_before'] ?? true) && (file_exists($a['target']) || is_link($a['target']))) {
			$this->saveBeforeTarget($a['target'], $a['root'], $a['login']);
			$lines[] = Lang::t('Current state backed up (restore point "pre-restore").');
		}

		$stage = $this->restoreDirectory($a['job']);
		$handedOver = false;
		try {
			[$code, , $err] = Restic::run([
				'restore', '--quiet', $a['id'], '--target', $stage, '--include', self::pattern($a['source']),
			], self::TIMEOUT, true);
			if ($code !== 0) {
				throw new RuntimeException(Lang::t('restic restore: %s', trim(Restic::redact($err))));
			}
			$source = $stage . $a['source'];
			if (!file_exists($source) && !is_link($source)) {
				throw new RuntimeException(Lang::t('The path %s was not in the restore point.', $a['source']));
			}

			// The whole staging area belongs to the customer; that way the
			// customer can read it, and whatever is in it they could create
			// themselves anyway.
			$handedOver = true;
			$this->exec(['/usr/bin/chown', '-R', '-h', $a['uid'] . ':' . $a['gid'], $stage]);
			chmod($stage, 0700);

			$isDir = is_dir($source) && !is_link($source);
			$targetDir = $isDir ? $a['target'] : dirname($a['target']);
			$this->asUser($a['uid'], $a['gid'], ['/usr/bin/mkdir', '-p', '--', $targetDir]);
			$copy = $isDir
				? ['/usr/bin/cp', '-a', '--', $source . '/.', $a['target'] . '/']
				: ['/usr/bin/cp', '-a', '--', $source, $a['target']];
			$this->asUser($a['uid'], $a['gid'], $copy);
			$lines[] = Lang::t('Restored to %s', $a['target']);
		} finally {
			if ($handedOver) {
				$this->removeAsUser($a['uid'], $a['gid'], $stage);
			} else {
				$this->remove($stage);
			}
		}

		return $lines;
	}

	/**
	 * Restores one table or the whole database.
	 *
	 * @param string|null $table null = all tables
	 * @return string[]
	 */
	public function restoreDatabase(string $id, string $login, string $db, ?string $table, string $mode, bool $saveBefore, DbAccess $access, int $job): array
	{
		$manifest = $this->manifest($id, $login);
		$entry = null;
		foreach ((array)($manifest['databases'] ?? []) as $d) {
			if (($d['name'] ?? '') === $db) {
				$entry = $d;
			}
		}
		if ($entry === null || !empty($entry['failed'])) {
			throw new RuntimeException(Lang::t('Database %s was not (completely) backed up in this point.', $db));
		}

		$files = [];
		foreach ((array)$entry['tables'] as $t) {
			$files[(string)$t['name']] = (string)$t['file'];
		}
		if ($table !== null && !isset($files[$table])) {
			throw new RuntimeException(Lang::t('Table %s does not exist in this point.', $table));
		}
		$selection = $table !== null ? [$table => $files[$table]] : $files;

		$lines = [];
		$work = $this->base . '/' . self::DB_SUBDIR . '/' . $job;
		$this->fresh($work);
		try {
			$sourceDir = $this->customerStaging($login) . '/' . Snapshotter::DB_SUBDIR . '/' . $db;
			[$code, , $err] = Restic::run([
				'restore', '--quiet', $id, '--target', $work, '--include', self::pattern($sourceDir),
			], self::TIMEOUT, true);
			if ($code !== 0) {
				throw new RuntimeException(Lang::t('restic restore: %s', trim(Restic::redact($err))));
			}
			$dumps = $work . $sourceDir;

			$target = $db;
			if ($mode === RestoreJob::MODE_COPY) {
				$target = $db . '_restore';
				if (strlen($target) > 64) {
					throw new RuntimeException(Lang::t('The name of the copy would be longer than 64 characters.'));
				}
				$access->pdo()->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $target) . '` CHARACTER SET utf8mb4');
				// The customer's database user (named like the database) gets the
				// same access to the copy as to the original, on every host it exists for.
				$granted = $access->grantAll($target, $db);
				$lines[] = $granted > 0
					? Lang::t('Copy in database %s (not registered in Froxlor; the database user %s has access, e.g. via phpMyAdmin).', $target, $db)
					: Lang::t('Copy in database %s (not registered in Froxlor; no database user %s found to grant access to).', $target, $db);
			} else {
				if ($saveBefore) {
					$beforeDir = $this->base . '/' . self::DB_SUBDIR . '/before-' . $job;
					$this->fresh($beforeDir);
					try {
						$saved = 0;
						$existing = $access->tables($db);
						foreach (array_keys($selection) as $t) {
							if (DbAccess::safeTableName((string)$t) && in_array($t, $existing, true)) {
								$access->dump($db, $t, $beforeDir . '/' . DbAccess::fileName($t), $beforeDir);
								$saved++;
							}
						}
						if ($saved > 0) {
							$this->saveBefore([$beforeDir], $login);
							$lines[] = Lang::t('Current state of %d table(s) backed up (restore point "pre-restore").', $saved);
						}
					} finally {
						$this->remove($beforeDir);
					}
				}
				if ($table === null && is_file($dumps . '/' . Snapshotter::SCHEMA_FILE)) {
					// The structure dump holds the customer's views, routines,
					// triggers and events and is imported with the server's root
					// account: every DEFINER becomes the database's own user
					// (Froxlor names it like the database), and anything that
					// still ends up with another definer is dropped.
					$access->import($db, $dumps . '/' . Snapshotter::SCHEMA_FILE, $work, $db, $access->userHosts($db));
					foreach ($access->dropForeignDefiners($db, $db) as $object) {
						$lines[] = Lang::t('Removed %s: it would have run with another account\'s rights.', $object);
					}
				}
			}

			foreach ($selection as $name => $file) {
				$access->import($target, $dumps . '/' . $file, $work);
				$lines[] = Lang::t('Table %s restored.', DbAccess::printable((string)$name));
			}
			// A table dump drops and recreates its table, and with it the
			// table's triggers: after a full restore they are applied again,
			// with the same DEFINER handling as the structure.
			if ($mode !== RestoreJob::MODE_COPY && $table === null && is_file($dumps . '/' . Snapshotter::TRIGGERS_FILE)) {
				$access->import($db, $dumps . '/' . Snapshotter::TRIGGERS_FILE, $work, $db, $access->userHosts($db));
				foreach ($access->dropForeignDefiners($db, $db) as $object) {
					$lines[] = Lang::t('Removed %s: it would have run with another account\'s rights.', $object);
				}
			}
		} finally {
			$this->remove($work);
		}

		return $lines;
	}

	/**
	 * The tables of a database dump in the point, for the preview.
	 *
	 * @return string[]
	 */
	public function databasePreview(string $id, string $login, string $db, ?string $table): array
	{
		$manifest = $this->manifest($id, $login);
		foreach ((array)($manifest['databases'] ?? []) as $d) {
			if (($d['name'] ?? '') !== $db) {
				continue;
			}
			if (!empty($d['failed'])) {
				throw new RuntimeException(Lang::t('Database %s was not completely backed up in this point.', $db));
			}
			$names = array_column((array)$d['tables'], 'name');
			if ($table !== null && !in_array($table, $names, true)) {
				throw new RuntimeException(Lang::t('Table %s does not exist in this point. Available: %s', $table, implode(', ', $names)));
			}

			return $table !== null ? [$table] : $names;
		}
		throw new RuntimeException(Lang::t('Database %s does not exist in this point.', $db));
	}

	/** Where the snapshots keep the staging directories (see Snapshotter). */
	private function stagingDir(): string
	{
		return $this->base . '/' . Snapshotter::WORK_SUBDIR;
	}

	private function customerStaging(string $login): string
	{
		return $this->stagingDir() . '/' . Snapshotter::CUSTOMERS_SUBDIR . '/' . $login;
	}

	/**
	 * Saves the current state of paths as "pre-restore". If that fails, the
	 * restore stops before anything has been overwritten.
	 *
	 * @param string[] $paths
	 */
	private function saveBefore(array $paths, string $login): void
	{
		$args = ['backup', '--quiet',
			'--tag', Tags::type(Tags::TYPE_PRE_RESTORE), '--tag', Tags::customer($login), '--tag', Tags::run(Tags::RUN_RESTORE), '--'];
		[$code, , $err] = Restic::run(array_merge($args, $paths), self::TIMEOUT, true);
		if ($code !== 0 && $code !== Snapshotter::EXIT_PARTIAL) {
			throw new RuntimeException(Lang::t('The current state could not be backed up; nothing was changed: %s', trim(Restic::redact($err))));
		}
	}

	/**
	 * Like saveBefore() for a path in a customer's tree, which the customer can
	 * change while root reads it. Root therefore backs up the path with all
	 * directories above it already resolved (restic itself never follows a link
	 * inside the tree), and afterwards checks that the directory above still
	 * resolves to the same place inside the allowed root. If it does not, the
	 * customer swapped a directory for a link during the backup: the new
	 * snapshot is deleted again before anyone can restore it, and the restore
	 * stops before anything has been overwritten.
	 */
	private function saveBeforeTarget(string $target, string $root, string $login): void
	{
		$parent = realpath(dirname($target));
		if ($parent === false) {
			throw new RuntimeException(Lang::t('The target %s is outside %s.', $target, $root));
		}
		$this->checkTarget($parent . '/' . basename($target), $root);
		$path = $parent . '/' . basename($target);

		$args = ['backup', '--json',
			'--tag', Tags::type(Tags::TYPE_PRE_RESTORE), '--tag', Tags::customer($login), '--tag', Tags::run(Tags::RUN_RESTORE), '--', $path];
		[$code, $out, $err] = Restic::run($args, self::TIMEOUT, true);
		if ($code !== 0 && $code !== Snapshotter::EXIT_PARTIAL) {
			throw new RuntimeException(Lang::t('The current state could not be backed up; nothing was changed: %s', trim(Restic::redact($err))));
		}

		if (realpath(dirname($target)) === $parent) {
			return;
		}
		foreach (preg_split('~\R~', $out) ?: [] as $line) {
			$msg = json_decode($line, true);
			if (is_array($msg) && ($msg['message_type'] ?? '') === 'summary' && preg_match('~^[0-9a-f]{8,64}$~', (string)($msg['snapshot_id'] ?? ''))) {
				Restic::run(['forget', '--prune', $msg['snapshot_id']], self::TIMEOUT);
			}
		}
		throw new RuntimeException(Lang::t('The target changed during the backup; nothing was changed.'));
	}

	/**
	 * The target must lie below the allowed root, checked at the deepest
	 * existing directory (all links resolved).
	 */
	private function checkTarget(string $target, string $root): void
	{
		$r = realpath($root);
		if ($r === false) {
			throw new RuntimeException(Lang::t('The directory %s does not exist.', $root));
		}
		$probe = $target;
		while ($probe !== '/' && $probe !== '' && !file_exists($probe)) {
			$probe = dirname($probe);
		}
		$real = realpath($probe);
		if ($real === false || !($real === $r || str_starts_with($real . '/', rtrim($r, '/') . '/'))) {
			throw new RuntimeException(Lang::t('The target %s is outside %s.', $target, $r));
		}
	}

	/**
	 * Escapes special characters so restic reads the path as a path and not
	 * as a pattern (* ? [ and backslash).
	 */
	private static function pattern(string $path): string
	{
		return preg_replace('~([*?\[\\\\])~', '\\\\$1', $path);
	}

	private function restoreDirectory(int $job): string
	{
		$root = $this->base . '/' . self::FILES_SUBDIR;
		// Customers must be able to pass through this directory and its
		// parent to their own subfolder, but not list them.
		SecureDir::ensure($this->base, 0711);
		SecureDir::ensure($root, 0711);

		$stage = $root . '/' . $job;
		$this->remove($stage);
		if (!@mkdir($stage, 0700)) {
			throw new RuntimeException(Lang::t('Could not create %s.', $stage));
		}

		return $stage;
	}

	private function fresh(string $path): void
	{
		SecureDir::ensure(dirname($path), 0700);
		$this->remove($path);
		if (!@mkdir($path, 0700)) {
			throw new RuntimeException(Lang::t('Could not create %s.', $path));
		}
	}

	/** Environment of customer processes: nothing from root's cron environment. */
	private const CUSTOMER_ENV = ['PATH=/usr/bin:/bin', 'HOME=/nonexistent', 'LC_ALL=C'];

	/**
	 * Runs a command as the customer: setpriv to uid/gid without supplementary
	 * groups and with no-new-privs, then a scrubbed environment (env -i with
	 * an allowlist). The working directory is "/" - root never changes into a
	 * customer directory; all paths are absolute and are resolved by the
	 * customer process itself, with the customer's rights.
	 *
	 * @param string[] $command
	 */
	private function asUser(int $uid, int $gid, array $command): void
	{
		$this->exec(array_merge(
			['/usr/bin/setpriv', '--reuid=' . $uid, '--regid=' . $gid, '--clear-groups', '--no-new-privs', '--',
				'/usr/bin/env', '-i'],
			self::CUSTOMER_ENV,
			['/usr/bin/timeout', '--kill-after=30', '3600'],
			$command
		));
	}

	/**
	 * Starts a command without a shell interpreting any argument. A small
	 * bash prefix first closes every inherited file descriptor above 2 - the
	 * cron holds Froxlor's root database connection, which must never reach
	 * a customer process - then replaces itself with the command. stdin is
	 * /dev/null, the working directory "/".
	 *
	 * @param string[] $command
	 */
	private function exec(array $command): void
	{
		$closeFds = 'for f in /proc/self/fd/*; do n=${f##*/}; case $n in 0|1|2|*[!0-9]*|"") ;; *) eval "exec $n>&-" 2>/dev/null ;; esac; done; exec "$@"';
		$p = @proc_open(
			array_merge(['/bin/bash', '-c', $closeFds, 'froxlor-backup'], $command),
			[0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
			'/',
			['PATH' => '/usr/bin:/bin', 'HOME' => '/nonexistent', 'LC_ALL' => 'C']
		);
		if (!is_resource($p)) {
			throw new RuntimeException(Lang::t('Could not start %s.', basename($command[0])));
		}
		stream_get_contents($pipes[1]);
		$err = (string)stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$code = proc_close($p);
		if ($code !== 0) {
			throw new RuntimeException(Lang::t('%s (exit %d): %s', basename($command[0]), $code, DbAccess::printable(trim($err), 2000, true)));
		}
	}

	/**
	 * Empties a staging directory that already belongs to the customer, as the
	 * customer, and only removes the then empty directory as root.
	 *
	 * Root must not walk through a directory tree the customer controls: the
	 * customer could swap a subdirectory for a symlink between the check and
	 * the chmod/unlink, and root would then act on foreign files. The staging
	 * directory itself cannot be swapped, its parent belongs to root.
	 */
	private function removeAsUser(int $uid, int $gid, string $stage): void
	{
		try {
			$this->asUser($uid, $gid, ['/usr/bin/chmod', '-R', 'u+rwX', '--', $stage]);
			$this->asUser($uid, $gid, ['/usr/bin/find', $stage, '-mindepth', '1', '-delete']);
		} catch (RuntimeException) {
			// Leftovers belong to the customer and contain only their own data.
		}
		@rmdir($stage);
	}

	private function remove(string $path): void
	{
		if (is_link($path) || is_file($path)) {
			@unlink($path);
			return;
		}
		if (!is_dir($path)) {
			return;
		}
		// Open permissions first: restic restores folder modes from the backup.
		@chmod($path, 0700);
		foreach (scandir($path) ?: [] as $name) {
			if ($name !== '.' && $name !== '..') {
				$this->remove($path . '/' . $name);
			}
		}
		@rmdir($path);
	}
}
