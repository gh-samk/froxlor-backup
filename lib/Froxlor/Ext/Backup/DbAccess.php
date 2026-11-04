<?php

namespace Froxlor\Ext\Backup;

use PDO;
use RuntimeException;

/**
 * Database credentials for the backup, read from Froxlor's
 * lib/userdata.inc.php (readable by root only), and the dump/import calls.
 *
 * Customer databases sit behind Froxlor's root access per database server
 * (nobody knows the passwords of the customers' database users). The panel
 * database is dumped with Froxlor's own user.
 *
 * Passwords never reach mysqldump as an argument; they go through a
 * temporary options file (0600) in the root-owned working directory.
 */
class DbAccess
{
	/** @var array{host:string, port:int, socket:string, user:string, password:string, db:string, ca_file:string, verify_cert:bool} */
	private array $access;

	private function __construct(array $access)
	{
		$this->access = $access;
	}

	/**
	 * @param string $froxlor Froxlor installation directory
	 * @param int|null $dbserver number of the database server (sql_root);
	 *                           null = Froxlor's own user for the panel database
	 */
	public static function load(string $froxlor, ?int $dbserver): self
	{
		$file = rtrim($froxlor, '/') . '/lib/userdata.inc.php';
		if (!is_file($file)) {
			throw new RuntimeException(Lang::t('lib/userdata.inc.php not found: %s', $file));
		}

		// Parsed, not included: the file belongs to the panel user.
		[$sql, $sqlRoot] = DbConfig::readUserdata($file);

		$source = $dbserver === null ? $sql : ($sqlRoot[$dbserver] ?? $sqlRoot[(string)$dbserver] ?? null);
		if (!is_array($source) || !isset($source['user'], $source['password'])) {
			throw new RuntimeException(Lang::t('No credentials for DB server %s in userdata.inc.php.', (string)($dbserver ?? 'panel')));
		}

		return new self(DbConfig::normalize($source, $dbserver === null ? (string)($sql['db'] ?? '') : ''));
	}

	/** Name of the database in the credentials (set for the panel database). */
	public function defaultDatabase(): string
	{
		return $this->access['db'];
	}

	public function pdo(?string $database = null): PDO
	{
		return DbConfig::pdo($this->access, $database);
	}

	/**
	 * Tables (no views) of a database.
	 *
	 * @return string[]
	 */
	public function tables(string $database): array
	{
		self::checkName($database);
		$stmt = $this->pdo()->query('SHOW FULL TABLES FROM `' . str_replace('`', '``', $database) . "` WHERE Table_type = 'BASE TABLE'");

		return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN, 0));
	}

	/**
	 * Writes a gzip-compressed dump to $target.
	 *
	 * @param string|null $table null = structure only, of all tables plus
	 *                           views, triggers and routines
	 * @param string $workDir root-owned directory for the options file
	 * @param bool $triggersOnly only the triggers of all tables, each preceded
	 *                           by DROP TRIGGER IF EXISTS ($table must be null).
	 *                           Re-applied after a full restore: importing a
	 *                           table dump drops the table and with it its triggers.
	 */
	public function dump(string $database, ?string $table, string $target, string $workDir, bool $triggersOnly = false): void
	{
		self::checkName($database);
		if ($table !== null && !self::safeTableName($table)) {
			throw new RuntimeException(Lang::t('Table name refused (starts with "-" or contains control characters): %s', self::printable($table)));
		}

		$optionsFile = $this->optionsFile($workDir);
		try {
			$command = [
				self::dumpBinary(),
				'--defaults-extra-file=' . $optionsFile, // must be the first argument
				'--single-transaction', '--quick', '--skip-lock-tables', '--no-tablespaces',
				'--hex-blob', '--default-character-set=utf8mb4',
			];
			// "--" ends the options: database and table names chosen by a
			// customer can never be read as an option such as --result-file.
			if ($triggersOnly) {
				array_push($command, '--no-data', '--no-create-info', '--no-create-db', '--triggers', '--add-drop-trigger', '--skip-routines', '--skip-events', '--', $database);
			} elseif ($table === null) {
				array_push($command, '--no-data', '--routines', '--triggers', '--events', '--databases', '--', $database);
			} else {
				array_push($command, '--skip-triggers', '--', $database, $table);
			}

			$process = @proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, '/', ['PATH' => '/usr/local/bin:/usr/bin:/bin']);
			if (!is_resource($process)) {
				throw new RuntimeException(Lang::t('The dump could not be started.'));
			}

			$gz = gzopen($target, 'wb6');
			if ($gz === false) {
				proc_terminate($process);
				throw new RuntimeException(Lang::t('Could not write %s.', $target));
			}
			@chmod($target, 0600);

			stream_set_blocking($pipes[2], false);
			$err = '';
			while (!feof($pipes[1])) {
				$chunk = fread($pipes[1], 262144);
				if ($chunk !== false && $chunk !== '') {
					gzwrite($gz, $chunk);
				}
				$err .= (string)fread($pipes[2], 8192);
			}
			gzclose($gz);
			$err .= (string)stream_get_contents($pipes[2]);
			fclose($pipes[1]);
			fclose($pipes[2]);
			$code = proc_close($process);

			if ($code !== 0) {
				@unlink($target);
				throw new RuntimeException(Lang::t('Dump of %s failed: %s', $database . ($table !== null ? '.' . $table : ''), str_replace($this->access['password'], '***', trim($err))));
			}
		} finally {
			@unlink($optionsFile);
		}
	}

	/**
	 * Loads a gzip-compressed dump into a database.
	 *
	 * The dump comes from our own backup, but is still only passed to the
	 * client as a data stream, never as an argument or shell text.
	 *
	 * With $definer set (structure dumps of customer databases, imported
	 * through the server's root account), every DEFINER clause is rewritten
	 * to that account, keeping only a plain host part. Without this, a view,
	 * routine, trigger or event without a usable DEFINER would be created
	 * with root as its definer and run with root's rights. Routines and the
	 * like keep working: Froxlor's database user of a database has the same
	 * name as the database.
	 */
	public function import(string $database, string $gzFile, string $workDir, ?string $definer = null, array $definerHosts = []): void
	{
		self::checkName($database);
		if ($definer !== null) {
			self::checkName($definer);
		}
		$gz = gzopen($gzFile, 'rb');
		if ($gz === false) {
			throw new RuntimeException(Lang::t('Could not read %s.', basename($gzFile)));
		}

		$optionsFile = $this->optionsFile($workDir);
		try {
			$command = [self::clientBinary(), '--defaults-extra-file=' . $optionsFile, '--default-character-set=utf8mb4', '--', $database];
			$process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, '/', ['PATH' => '/usr/local/bin:/usr/bin:/bin']);
			if (!is_resource($process)) {
				throw new RuntimeException(Lang::t('The database client could not be started.'));
			}
			stream_set_blocking($pipes[2], false);
			$err = '';
			$refused = null;
			while (!gzeof($gz)) {
				if ($definer === null) {
					$chunk = gzread($gz, 262144);
				} else {
					// Line by line, so a DEFINER clause is never split between
					// two chunks; an endless line is refused instead.
					$chunk = gzgets($gz, 1048576);
					if ($chunk !== false && !str_ends_with($chunk, "\n") && !gzeof($gz)) {
						$refused = Lang::t('The structure dump contains a line that is too long - not imported.');
						break;
					}
					if ($chunk !== false) {
						$chunk = self::rewriteDefiner($chunk, $definer, $definerHosts);
					}
				}
				if ($chunk === false || $chunk === '') {
					break;
				}
				// If the client stops early (syntax error), fwrite fails.
				if (@fwrite($pipes[0], $chunk) === false) {
					break;
				}
				$err .= (string)fread($pipes[2], 8192);
			}
			gzclose($gz);
			fclose($pipes[0]);
			stream_get_contents($pipes[1]);
			$err .= (string)stream_get_contents($pipes[2]);
			fclose($pipes[1]);
			fclose($pipes[2]);
			$code = proc_close($process);
			if ($refused !== null) {
				throw new RuntimeException($refused);
			}
			if ($code !== 0) {
				throw new RuntimeException(Lang::t('Import into %s failed: %s', $database, str_replace($this->access['password'], '***', trim($err))));
			}
		} finally {
			@unlink($optionsFile);
		}
	}

	/**
	 * Replaces every DEFINER clause in a piece of SQL with
	 * DEFINER=`$user`@`<host>`. The host must exist for that user, otherwise
	 * views and triggers fail with "definer does not exist": the original
	 * host if it is one of $hosts, else localhost if it is, else the first
	 * of $hosts. Without known hosts, localhost.
	 *
	 * @param string[] $hosts hosts the user exists for (mysql.user)
	 */
	public static function rewriteDefiner(string $sql, string $user, array $hosts = []): string
	{
		$name = '(?:`(?:[^`]|``)*`|\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"|[A-Za-z0-9_$.%:-]+)';
		$hosts = array_values(array_filter($hosts, static fn($h) => is_string($h) && preg_match('~^[A-Za-z0-9_.%:-]{1,255}$~', $h)));

		return (string)preg_replace_callback(
			'~DEFINER\s*=\s*(?:CURRENT_USER(?:\s*\(\s*\))?|' . $name . '\s*@\s*(' . $name . '))~i',
			static function (array $m) use ($user, $hosts): string {
				$original = isset($m[1]) ? trim($m[1], '`\'"') : '';
				if (in_array($original, $hosts, true)) {
					$host = $original;
				} elseif ($hosts === [] || in_array('localhost', $hosts, true)) {
					$host = 'localhost';
				} else {
					$host = $hosts[0];
				}

				return 'DEFINER=`' . $user . '`@`' . $host . '`';
			},
			$sql
		);
	}

	/**
	 * Hosts a database user exists for (mysql.user), localhost first.
	 *
	 * @return string[]
	 */
	public function userHosts(string $user): array
	{
		self::checkName($user);
		try {
			$stmt = $this->pdo()->prepare('SELECT DISTINCT `Host` FROM `mysql`.`user` WHERE `User` = ?');
			$stmt->execute([$user]);
			$hosts = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN, 0));
		} catch (\PDOException $e) {
			return [];
		}
		usort($hosts, static fn($a, $b) => ($b === 'localhost') <=> ($a === 'localhost'));

		return $hosts;
	}

	/**
	 * Grants $user all privileges on $database, for every host the user
	 * exists for. Returns the number of grants.
	 */
	public function grantAll(string $database, string $user): int
	{
		self::checkName($database);
		$pdo = $this->pdo();
		$n = 0;
		foreach ($this->userHosts($user) as $host) {
			try {
				$pdo->exec('GRANT ALL PRIVILEGES ON `' . str_replace('`', '``', $database) . '`.* TO ' . $pdo->quote($user) . '@' . $pdo->quote($host));
				$n++;
			} catch (\PDOException $e) {
				// No GRANT privilege: the copy stays, the log says nobody got access.
			}
		}

		return $n;
	}

	/**
	 * After a structure import: drops every view, routine, trigger and event
	 * of the database whose definer is not $user, so nothing in it can run
	 * with another account's rights - a safety net behind rewriteDefiner().
	 *
	 * @return string[] the dropped objects, for the log
	 */
	public function dropForeignDefiners(string $database, string $user): array
	{
		self::checkName($database);
		self::checkName($user);
		$pdo = $this->pdo();
		$q = static fn(string $id): string => '`' . str_replace('`', '``', $id) . '`';
		$db = $q($database);
		$queries = [
			"SELECT ROUTINE_TYPE AS kind, ROUTINE_NAME AS name, DEFINER AS definer FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ?",
			"SELECT 'TRIGGER' AS kind, TRIGGER_NAME AS name, DEFINER AS definer FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ?",
			"SELECT 'EVENT' AS kind, EVENT_NAME AS name, DEFINER AS definer FROM information_schema.EVENTS WHERE EVENT_SCHEMA = ?",
			"SELECT 'VIEW' AS kind, TABLE_NAME AS name, DEFINER AS definer FROM information_schema.VIEWS WHERE TABLE_SCHEMA = ?",
		];
		$dropped = [];
		foreach ($queries as $sql) {
			$stmt = $pdo->prepare($sql);
			$stmt->execute([$database]);
			foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
				$owner = explode('@', (string)$row['definer'], 2)[0];
				if ($owner === $user) {
					continue;
				}
				$kind = strtoupper((string)$row['kind']);
				if (!in_array($kind, ['PROCEDURE', 'FUNCTION', 'TRIGGER', 'EVENT', 'VIEW'], true)) {
					continue;
				}
				$pdo->exec('DROP ' . $kind . ' IF EXISTS ' . $db . '.' . $q((string)$row['name']));
				$dropped[] = strtolower($kind) . ' ' . self::printable((string)$row['name']) . ' (' . self::printable((string)$row['definer']) . ')';
			}
		}

		return $dropped;
	}

	/**
	 * Table names come from the customer's database and end up as an
	 * argument of mariadb-dump. Besides the "--" separator, names that could
	 * be mistaken for an option or break a log line are refused.
	 */
	public static function safeTableName(string $table): bool
	{
		return $table !== '' && strlen($table) <= 192 && $table[0] !== '-' && !preg_match('~[\x00-\x1f\x7f]~', $table);
	}

	/** A name for a log line: control characters replaced, length capped. */
	public static function printable(string $text, int $max = 80, bool $multiline = false): string
	{
		$text = (string)preg_replace($multiline ? '~[\x00-\x08\x0b-\x1f\x7f]~' : '~[\x00-\x1f\x7f]~', '?', $text);
		// Without mbstring (not needed by the cron): cut at a character, not a byte.
		if (preg_match('~^.{0,' . max(1, $max) . '}~su', $text, $m) === 1) {
			return $m[0];
		}

		return substr($text, 0, $max);
	}

	/**
	 * File name for a table: the name itself if it is harmless, otherwise a
	 * hash. The original name is recorded in the manifest.
	 */
	public static function fileName(string $table): string
	{
		if (preg_match('~^[A-Za-z0-9_-]{1,100}$~', $table)) {
			return $table . '.sql.gz';
		}

		return 'table-' . md5($table) . '.sql.gz';
	}

	private static function clientBinary(): string
	{
		foreach (['/usr/bin/mariadb', '/usr/bin/mysql', '/usr/local/bin/mariadb', '/usr/local/bin/mysql'] as $path) {
			if (is_executable($path)) {
				return $path;
			}
		}
		throw new RuntimeException(Lang::t('Neither mariadb nor mysql found.'));
	}

	/**
	 * Database names end up in commands and backtick expressions. Froxlor
	 * only assigns letters, digits, underscore and hyphen.
	 */
	private static function checkName(string $name): void
	{
		if (!preg_match('~^[A-Za-z0-9_$-]{1,64}$~', $name)) {
			throw new RuntimeException(Lang::t('Invalid database name: %s', $name));
		}
	}

	private static function dumpBinary(): string
	{
		foreach (['/usr/bin/mariadb-dump', '/usr/bin/mysqldump', '/usr/local/bin/mariadb-dump', '/usr/local/bin/mysqldump'] as $path) {
			if (is_executable($path)) {
				return $path;
			}
		}
		throw new RuntimeException(Lang::t('Neither mariadb-dump nor mysqldump found.'));
	}

	private function optionsFile(string $dir): string
	{
		$lines = DbConfig::clientOptions($this->access);

		$path = rtrim($dir, '/') . '/dump-' . bin2hex(random_bytes(6)) . '.cnf';
		$old = umask(0077);
		try {
			// "x": never follow or reuse an existing file at this path.
			$fp = @fopen($path, 'xb');
			$content = implode("\n", $lines) . "\n";
			if ($fp === false || fwrite($fp, $content) !== strlen($content) || !fclose($fp)) {
				@unlink($path);
				throw new RuntimeException(Lang::t('Could not write the options file.'));
			}
		} finally {
			umask($old);
		}

		return $path;
	}
}
