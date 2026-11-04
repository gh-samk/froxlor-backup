<?php

namespace Froxlor\Ext\Backup;

use RuntimeException;

/**
 * The actual backup: one restic snapshot per customer, plus one for the
 * server itself.
 *
 * This class does not know Froxlor's database; it receives the finished
 * manifest and can therefore be tested on its own. BackupRun orchestrates it.
 *
 * A customer snapshot contains:
 *   - the customer's web directory (documentroot),
 *   - the customer's maildir directory,
 *   - a staging directory with manifest.json and db/<name>/<table>.sql.gz.
 * Tags: type:customer, customer:<login>, run:<intermediate|nightly> (see Tags).
 *
 * The staging paths below are recorded inside every snapshot; the restore
 * code looks for the manifest and the dumps there.
 */
class Snapshotter
{
	/** Root of everything this extension writes locally. */
	public const BASE_DIR = '/var/lib/froxlor-backup';

	/** Staging area inside BASE_DIR: <work>/customers/<login>, <work>/system. */
	public const WORK_SUBDIR = 'work';
	public const CUSTOMERS_SUBDIR = 'customers';
	public const SYSTEM_SUBDIR = 'system';
	public const PANEL_DB_SUBDIR = 'panel-database';
	public const DB_SUBDIR = 'db';
	public const SCHEMA_FILE = '_schema.sql.gz';
	public const TRIGGERS_FILE = '_triggers.sql.gz';

	/** restic exits with 3 if single files were unreadable; the snapshot still exists. */
	public const EXIT_PARTIAL = 3;

	/** Upper limit for one restic run: six hours. */
	private const TIMEOUT = 21600;

	private string $froxlor;
	private string $work;

	/** @var array<int,DbAccess> */
	private array $access = [];

	public function __construct(string $froxlor, string $work = self::BASE_DIR . '/' . self::WORK_SUBDIR)
	{
		$this->froxlor = $froxlor;
		$this->work = rtrim($work, '/');
	}

	/**
	 * Backs up one customer.
	 *
	 * @param array $manifest       result of Manifest::forCustomer()
	 * @param array $databases      result of Manifest::databasesOfCustomer()
	 * @param string[] $allowedRoots directories that web and mail paths must be under
	 * @return string[] log lines; errors of single databases appear as "ERROR:" lines
	 */
	public function customer(array $manifest, array $databases, string $run, array $allowedRoots): array
	{
		$login = (string)$manifest['customer']['login'];
		if (!preg_match('~^[A-Za-z0-9_-]{1,50}$~', $login)) {
			throw new RuntimeException(Lang::t('Invalid customer name: %s', $login));
		}

		$stage = $this->work . '/' . self::CUSTOMERS_SUBDIR . '/' . $login;
		$this->emptyDirectory($stage);

		$lines = [];
		try {
			foreach ($databases as $db) {
				try {
					[$entry, $warnings] = $this->backupDatabase($db, $stage);
					$manifest['databases'][] = $entry;
					array_push($lines, ...$warnings);
				} catch (\Throwable $e) {
					$lines[] = Lang::t('ERROR: Database %s: %s', $db['name'], $e->getMessage());
					$manifest['databases'][] = ['id' => $db['id'], 'name' => $db['name'], 'server' => $db['server'], 'failed' => true, 'tables' => []];
				}
			}

			file_put_contents($stage . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
			@chmod($stage . '/manifest.json', 0600);

			// Intermediate backups skip the mailboxes if so configured: otherwise
			// every run walks through all mails. The snapshot carries this in a
			// tag so the interface does not offer it for mailboxes.
			$withoutMail = $run !== Tags::RUN_NIGHTLY && BackupSettings::get('mail_nightly_only', '1') === '1';
			$mailPath = $withoutMail ? '' : ($manifest['mail_dir'] ?? '');
			$tags = [Tags::type(Tags::TYPE_CUSTOMER), Tags::customer($login), Tags::run($run)];
			if ($withoutMail) {
				$tags[] = Tags::WITHOUT_MAIL;
			}

			$paths = [$stage];
			foreach ([$manifest['customer']['documentroot'] ?? '', $mailPath] as $candidate) {
				$path = $this->customerPath((string)$candidate, $allowedRoots);
				if ($path !== null) {
					$paths[] = $path;
				} elseif ($candidate !== '' && file_exists((string)$candidate)) {
					$lines[] = Lang::t('ERROR: %s is outside the allowed directories and was skipped.', $candidate);
				}
			}

			$message = $this->snapshot($paths, $tags);
			$lines[] = $login . ': ' . $message;
		} finally {
			$this->remove($stage);
		}

		return $lines;
	}

	/**
	 * Backs up the server itself: panel database (per table), configuration,
	 * Froxlor, certificates and DKIM keys.
	 *
	 * @return string[]
	 */
	public function system(string $run): array
	{
		$stage = $this->work . '/' . self::SYSTEM_SUBDIR;
		$this->emptyDirectory($stage);

		$lines = [];
		try {
			$panel = DbAccess::load($this->froxlor, null);
			$name = $panel->defaultDatabase();
			$dir = $stage . '/' . self::PANEL_DB_SUBDIR;
			mkdir($dir, 0700);
			$files = [];
			foreach ($panel->tables($name) as $table) {
				if (!DbAccess::safeTableName($table)) {
					$lines[] = Lang::t('WARNING: Table %s was skipped: its name starts with "-" or contains control characters.', DbAccess::printable($table));
					continue;
				}
				$file = DbAccess::fileName($table);
				$panel->dump($name, $table, $dir . '/' . $file, $stage);
				$files[$table] = $file;
			}
			$panel->dump($name, null, $dir . '/' . self::SCHEMA_FILE, $stage);
			file_put_contents($stage . '/manifest.json', json_encode([
				'format' => Manifest::FORMAT,
				'created' => time(),
				'panel_database' => $name,
				'tables' => $files,
			], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
			@chmod($stage . '/manifest.json', 0600);

			$paths = [$stage];
			foreach (['/etc', rtrim($this->froxlor, '/'), '/var/lib/rspamd/dkim', '/etc/letsencrypt', '/root/.acme.sh'] as $candidate) {
				if (is_dir($candidate)) {
					$paths[] = $candidate;
				}
			}

			// /etc/froxlor-backup stays out: the credentials of the repository
			// do not belong into the repository.
			$lines[] = Lang::t('System: %s', $this->snapshot($paths, [Tags::type(Tags::TYPE_SYSTEM), Tags::run($run)], [Target::DIRECTORY]));
		} finally {
			$this->remove($stage);
		}

		return $lines;
	}

	/**
	 * Deletes restore points older than $days days.
	 *
	 * Only snapshots carrying this extension's tags: a repository that was
	 * filled by another tool before stays untouched. "keep-within" measures
	 * from the newest snapshot of the group, not from today; the last state of
	 * a deleted customer is therefore kept until someone removes it by hand.
	 */
	public function cleanup(int $days): string
	{
		$days = max(1, $days);
		$this->unlockStale();
		[$code, , $err] = Restic::run([
			'forget', '--quiet', '--prune', '--keep-within', $days . 'd',
			'--tag', Tags::type(Tags::TYPE_CUSTOMER),
			'--tag', Tags::type(Tags::TYPE_SYSTEM),
			'--tag', Tags::type(Tags::TYPE_PRE_RESTORE),
		], self::TIMEOUT, true);
		if ($code !== 0) {
			throw new RuntimeException(Lang::t('Cleanup failed (is the key allowed to delete?): %s', trim(Restic::redact($err))));
		}

		return Lang::t('Cleaned up: restore points older than %d days removed.', $days);
	}

	/**
	 * Checks the repository by sampling (structure plus 2 % of the data).
	 */
	public function check(): string
	{
		$this->unlockStale();
		[$code, , $err] = Restic::run(['check', '--quiet', '--read-data-subset=2%'], self::TIMEOUT, true);
		if ($code !== 0) {
			throw new RuntimeException(Lang::t('Repository check reported errors: %s', trim(Restic::redact($err))));
		}

		return Lang::t('Repository checked (structure and %d%% of the data).', 2);
	}

	/**
	 * Removes stale locks (left behind by a restic process that died, e.g.
	 * after a reboot) at the start of a run and before forget/prune/check,
	 * which need an exclusive lock. "restic unlock" without --remove-all leaves locks of running
	 * processes alone. A failure here is not fatal: the next step reports it.
	 */
	public function unlockStale(): void
	{
		try {
			Restic::run(['unlock', '--quiet'], 300);
		} catch (\Throwable $e) {
			// The following command will report the problem.
		}
	}

	/**
	 * Dumps a database: structure plus every table separately.
	 *
	 * Table names are chosen by the customer. A name that starts with "-" or
	 * contains control characters is skipped with a warning line (the rest of
	 * the database is still backed up); the dump call itself also ends the
	 * options with "--".
	 *
	 * @return array{0:array, 1:string[]} manifest entry and log lines
	 */
	private function backupDatabase(array $db, string $stage): array
	{
		$server = (int)$db['server'];
		$access = $this->access[$server] ??= DbAccess::load($this->froxlor, $server);

		$dir = $stage . '/' . self::DB_SUBDIR . '/' . $db['name'];
		if (!preg_match('~^[A-Za-z0-9_$-]{1,64}$~', (string)$db['name'])) {
			throw new RuntimeException(Lang::t('Invalid database name'));
		}
		if (!is_dir($stage . '/' . self::DB_SUBDIR)) {
			mkdir($stage . '/' . self::DB_SUBDIR, 0700);
		}
		mkdir($dir, 0700);

		$access->dump($db['name'], null, $dir . '/' . self::SCHEMA_FILE, $stage);
		$access->dump($db['name'], null, $dir . '/' . self::TRIGGERS_FILE, $stage, true);
		$tables = [];
		$skipped = [];
		$warnings = [];
		foreach ($access->tables($db['name']) as $table) {
			if (!DbAccess::safeTableName($table)) {
				$skipped[] = DbAccess::printable($table);
				$warnings[] = Lang::t('WARNING: Database %s: table %s was skipped: its name starts with "-" or contains control characters.', $db['name'], DbAccess::printable($table));
				continue;
			}
			$file = DbAccess::fileName($table);
			$access->dump($db['name'], $table, $dir . '/' . $file, $stage);
			$tables[] = ['name' => $table, 'file' => $file];
		}

		return [[
			'id' => $db['id'],
			'name' => $db['name'],
			'server' => $server,
			'description' => $db['description'] ?? '',
			'tables' => $tables,
			'skipped_tables' => $skipped,
		], $warnings];
	}

	/**
	 * @param string[] $paths
	 * @param string[] $tags
	 * @param string[] $excludes additional paths to exclude
	 */
	private function snapshot(array $paths, array $tags, array $excludes = []): string
	{
		$args = ['backup', '--quiet', '--exclude-caches', '--exclude', 'wp-content/cache', '--exclude', 'wp-content/upgrade'];
		foreach ($excludes as $e) {
			array_push($args, '--exclude', $e);
		}
		foreach ($tags as $tag) {
			array_push($args, '--tag', $tag);
		}
		// "--" keeps a path from being read as an option.
		$args[] = '--';
		$args = array_merge($args, $paths);

		[$code, , $err] = Restic::run($args, self::TIMEOUT, true);
		if ($code === 0) {
			return Lang::t('backed up');
		}
		if ($code === self::EXIT_PARTIAL) {
			return Lang::t('backed up, some files were not readable');
		}

		throw new RuntimeException(Lang::t('restic backup (exit %d): %s', $code, trim(Restic::redact($err))));
	}

	/**
	 * A path from the customer database is only worth backing up if it
	 * exists, is a directory and, with all links resolved, lies below an
	 * allowed root. Prevents a wrongly set documentroot (/, /etc) from pulling
	 * a foreign area into the backup.
	 */
	private function customerPath(string $path, array $roots): ?string
	{
		if ($path === '') {
			return null;
		}
		$real = realpath($path);
		if ($real === false || !is_dir($real)) {
			return null;
		}
		foreach ($roots as $root) {
			$r = realpath($root);
			if ($r !== false && str_starts_with($real . '/', rtrim($r, '/') . '/') && $real !== $r) {
				return $real;
			}
		}

		return null;
	}

	private function emptyDirectory(string $path): void
	{
		// The working directory must belong to root and must not be a link
		// before dumps with database content end up in it.
		SecureDir::ensure($this->work, 0700);
		if (file_exists($path) || is_link($path)) {
			$this->remove($path);
		}
		SecureDir::ensure(dirname($path), 0700);
		if (!@mkdir($path, 0700)) {
			throw new RuntimeException(Lang::t('Could not create %s.', $path));
		}
	}

	/**
	 * Deletes a directory with its contents without following links.
	 */
	private function remove(string $path): void
	{
		if (is_link($path) || is_file($path)) {
			@unlink($path);
			return;
		}
		if (!is_dir($path)) {
			return;
		}
		foreach (scandir($path) ?: [] as $name) {
			if ($name !== '.' && $name !== '..') {
				$this->remove($path . '/' . $name);
			}
		}
		@rmdir($path);
	}
}
