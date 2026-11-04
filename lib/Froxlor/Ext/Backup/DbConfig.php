<?php

namespace Froxlor\Ext\Backup;

use PDO;

/**
 * Connection settings from Froxlor's lib/userdata.inc.php, interpreted
 * exactly like Froxlor's own Database class (2.3.x):
 *
 *   $sql      = ['host', 'socket'?, 'port'?, 'user', 'password', 'db',
 *                'ssl' => ['caFile', 'verifyServerCertificate']?]
 *   $sql_root = [<n> => ['caption', 'host', 'socket'?, 'port'?, 'user',
 *                'password', 'ssl' => [...]?]]
 *
 * A socket wins over host and port. TLS options only apply to TCP
 * connections, as in Froxlor.
 *
 * Self-contained on purpose (no other class, no Froxlor bootstrap): the
 * migration scripts require this file directly. userdata.inc.php is parsed,
 * never executed (see readUserdata).
 */
final class DbConfig
{
	/**
	 * Reads $sql and $sql_root from userdata.inc.php WITHOUT executing it.
	 *
	 * The file belongs to the panel's PHP user, while the code reading it
	 * often runs as root (installer, migrations, the backup cron). Including
	 * it would run whatever that user put there with root's rights. So the
	 * file is tokenized and only plain literal assignments are accepted:
	 * `$sql = [...];`, `$sql['key'] = ...;` with strings (no interpolation),
	 * nowdoc/heredoc without interpolation, numbers, booleans and null - the
	 * formats Froxlor writes. Anything else is refused.
	 *
	 * @return array{0:array, 1:array} $sql and $sql_root
	 * @throws \RuntimeException on unreadable files or anything but literal assignments
	 */
	public static function readUserdata(string $file): array
	{
		$vars = self::parseAssignments($file);

		return [
			is_array($vars['sql'] ?? null) ? $vars['sql'] : [],
			is_array($vars['sql_root'] ?? null) ? $vars['sql_root'] : [],
		];
	}

	/**
	 * @return array<string,mixed> variable name => literal value
	 * @throws \RuntimeException
	 */
	public static function parseAssignments(string $file): array
	{
		if (is_link($file) || !is_file($file)) {
			throw new \RuntimeException("$file is not a regular file.");
		}
		$code = @file_get_contents($file);
		if ($code === false) {
			throw new \RuntimeException("$file is not readable.");
		}
		$skip = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG];
		$tokens = array_values(array_filter(token_get_all($code), static fn($t) => !is_array($t) || !in_array($t[0], $skip, true)));
		$pos = 0;
		$vars = [];
		while (isset($tokens[$pos])) {
			$t = $tokens[$pos];
			if (!is_array($t) || $t[0] !== T_VARIABLE) {
				self::refuse($file, $tokens, $pos, 'a variable assignment');
			}
			$name = substr($t[1], 1);
			$pos++;
			$path = [];
			while (($tokens[$pos] ?? null) === '[') {
				$pos++;
				$key = self::literal($file, $tokens, $pos);
				if (!is_int($key) && !is_string($key)) {
					self::refuse($file, $tokens, $pos, 'a scalar array key');
				}
				$path[] = $key;
				self::expect($file, $tokens, $pos, ']');
			}
			self::expect($file, $tokens, $pos, '=');
			$value = self::literal($file, $tokens, $pos);
			self::expect($file, $tokens, $pos, ';');

			$ref = &$vars[$name];
			foreach ($path as $key) {
				if (!is_array($ref)) {
					$ref = [];
				}
				$ref = &$ref[$key];
			}
			$ref = $value;
			unset($ref);
		}

		return $vars;
	}

	/**
	 * One literal value (recursively for arrays) starting at $pos.
	 */
	private static function literal(string $file, array $tokens, int &$pos): mixed
	{
		$t = $tokens[$pos] ?? null;
		if ($t === '[' || (is_array($t) && $t[0] === T_ARRAY)) {
			$close = ']';
			$pos++;
			if ($t !== '[') {
				self::expect($file, $tokens, $pos, '(');
				$close = ')';
			}
			$result = [];
			while (($tokens[$pos] ?? null) !== $close) {
				if (!isset($tokens[$pos])) {
					self::refuse($file, $tokens, $pos, "'$close'");
				}
				$item = self::literal($file, $tokens, $pos);
				$next = $tokens[$pos] ?? null;
				if (is_array($next) && $next[0] === T_DOUBLE_ARROW) {
					$pos++;
					if (!is_int($item) && !is_string($item)) {
						self::refuse($file, $tokens, $pos, 'a scalar array key');
					}
					$result[$item] = self::literal($file, $tokens, $pos);
				} else {
					$result[] = $item;
				}
				if (($tokens[$pos] ?? null) === ',') {
					$pos++;
				} elseif (($tokens[$pos] ?? null) !== $close) {
					self::refuse($file, $tokens, $pos, "',' or '$close'");
				}
			}
			$pos++;

			return $result;
		}
		if ($t === '-') {
			$pos++;
			$n = self::literal($file, $tokens, $pos);
			if (!is_int($n) && !is_float($n)) {
				self::refuse($file, $tokens, $pos, 'a number');
			}

			return -$n;
		}
		if (is_array($t)) {
			switch ($t[0]) {
				case T_CONSTANT_ENCAPSED_STRING:
					$pos++;
					$body = substr($t[1], 1, -1);

					return $t[1][0] === "'" ? str_replace(['\\\\', "\\'"], ['\\', "'"], $body) : stripcslashes($body);
				case T_START_HEREDOC:
					$pos++;
					$body = '';
					$next = $tokens[$pos] ?? null;
					if (is_array($next) && $next[0] === T_ENCAPSED_AND_WHITESPACE) {
						$body = $next[1];
						$pos++;
					}
					$next = $tokens[$pos] ?? null;
					if (!is_array($next) || $next[0] !== T_END_HEREDOC) {
						self::refuse($file, $tokens, $pos, 'the end of a nowdoc/heredoc without interpolation');
					}
					$pos++;
					// The closing line break belongs to the delimiter, not to the value.
					$body = (string)preg_replace('/\r?\n$/', '', $body);

					return str_contains($t[1], "'") ? $body : stripcslashes($body);
				case T_LNUMBER:
					$pos++;

					return intval($t[1], 0);
				case T_DNUMBER:
					$pos++;

					return (float)$t[1];
				case T_STRING:
					$word = strtolower($t[1]);
					if (in_array($word, ['true', 'false', 'null'], true)) {
						$pos++;

						return $word === 'null' ? null : $word === 'true';
					}
			}
		}
		self::refuse($file, $tokens, $pos, 'a literal value');
	}

	private static function expect(string $file, array $tokens, int &$pos, string $char): void
	{
		if (($tokens[$pos] ?? null) !== $char) {
			self::refuse($file, $tokens, $pos, "'$char'");
		}
		$pos++;
	}

	private static function refuse(string $file, array $tokens, int $pos, string $what): never
	{
		$t = $tokens[$pos] ?? null;
		$near = is_array($t) ? token_name($t[0]) . ' ' . trim($t[1]) . ' (line ' . $t[2] . ')' : (string)($t ?? 'end of file');
		throw new \RuntimeException("Unsupported content in $file: expected $what near $near.");
	}

	/**
	 * Normalises one entry of $sql or $sql_root.
	 *
	 * @return array{host:string, port:int, socket:string, user:string, password:string, db:string, ca_file:string, verify_cert:bool}
	 */
	public static function normalize(array $entry, string $db = ''): array
	{
		$ssl = is_array($entry['ssl'] ?? null) ? $entry['ssl'] : [];

		return [
			'host' => (string)($entry['host'] ?? 'localhost'),
			'port' => (int)($entry['port'] ?? 3306) ?: 3306,
			'socket' => (string)($entry['socket'] ?? ''),
			'user' => (string)($entry['user'] ?? ''),
			'password' => (string)($entry['password'] ?? ''),
			'db' => $db,
			'ca_file' => (string)($ssl['caFile'] ?? ''),
			'verify_cert' => (bool)($ssl['verifyServerCertificate'] ?? false),
		];
	}

	/**
	 * The PDO DSN, as Froxlor builds it.
	 */
	public static function dsn(array $c, ?string $database = null, string $charset = 'utf8mb4'): string
	{
		$parts = ['charset=' . $charset];
		if ($database !== null && $database !== '') {
			$parts[] = 'dbname=' . $database;
		}
		if ($c['socket'] !== '') {
			$parts[] = 'unix_socket=' . $c['socket'];
		} else {
			$parts[] = 'host=' . $c['host'];
			$parts[] = 'port=' . $c['port'];
		}

		return 'mysql:' . implode(';', $parts);
	}

	/**
	 * PDO options: exceptions, plus CA file and verification for TCP.
	 *
	 * @return array<int,mixed>
	 */
	public static function pdoOptions(array $c): array
	{
		$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
		if ($c['socket'] === '' && $c['ca_file'] !== '') {
			$options[self::mysqlAttribute('SSL_CA')] = $c['ca_file'];
			$options[self::mysqlAttribute('SSL_VERIFY_SERVER_CERT')] = $c['verify_cert'];
		}

		return $options;
	}

	public static function pdo(array $c, ?string $database = null, string $charset = 'utf8mb4'): PDO
	{
		return new PDO(self::dsn($c, $database, $charset), $c['user'], $c['password'], self::pdoOptions($c));
	}

	/**
	 * The [client] section of an options file for mariadb-dump / mariadb
	 * (passed with --defaults-extra-file, never on the command line).
	 *
	 * @return string[] lines
	 */
	public static function clientOptions(array $c): array
	{
		$q = static fn(string $value): string => '"' . addcslashes($value, "\\\"") . '"';

		$lines = ['[client]', 'user=' . $q($c['user']), 'password=' . $q($c['password'])];
		if ($c['socket'] !== '') {
			$lines[] = 'socket=' . $q($c['socket']);
		} else {
			$lines[] = 'host=' . $q($c['host']);
			$lines[] = 'port=' . $c['port'];
			if ($c['ca_file'] !== '') {
				$lines[] = 'ssl-ca=' . $q($c['ca_file']);
				// "loose-": clients that do not know the option (MySQL 8 uses
				// ssl-mode instead) ignore it instead of refusing to start.
				$lines[] = $c['verify_cert'] ? 'loose-ssl-verify-server-cert' : 'loose-disable-ssl-verify-server-cert';
			}
		}

		return $lines;
	}

	/**
	 * A pdo_mysql driver attribute such as 'SSL_CA'. PHP 8.4 added
	 * Pdo\Mysql::ATTR_*, PHP 8.5 deprecates PDO::MYSQL_ATTR_*.
	 */
	public static function mysqlAttribute(string $name): int
	{
		if (defined('Pdo\\Mysql::ATTR_' . $name)) {
			return constant('Pdo\\Mysql::ATTR_' . $name);
		}

		return constant('PDO::MYSQL_ATTR_' . $name);
	}
}
