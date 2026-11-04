<?php

namespace Froxlor\Ext\Backup;

use RuntimeException;

/**
 * The storage target: restic's credentials and encryption password.
 *
 * Both live exclusively under /etc/froxlor-backup, readable by root only.
 * They are written by the cron (root), never by the panel; the panel
 * database sees the values only during the minute between submitting the
 * form and the cron picking up the job (see JobQueue::next).
 */
class Target
{
	public const DIRECTORY = '/etc/froxlor-backup';
	public const ENV_FILE = self::DIRECTORY . '/target.env';
	public const PASSWORD_FILE = self::DIRECTORY . '/restic-password';

	/** Valid target types. */
	public const TYPES = ['s3', 'sftp'];

	/**
	 * Labels of the target types in the user's language.
	 *
	 * @return array<string,string>
	 */
	public static function types(): array
	{
		return [
			's3' => Lang::t('S3 object storage'),
			'sftp' => Lang::t('SFTP (e.g. a storage box)'),
		];
	}

	/**
	 * Validates the form input and returns repository, environment and a
	 * description without secrets for display.
	 *
	 * The rules are strict on purpose: everything that later ends up in an
	 * environment file and a restic repository address must contain neither
	 * line breaks nor spaces nor quotes.
	 *
	 * @return array{repository:string, env:array<string,string>, label:string, details:array<string,string>}
	 * @throws RuntimeException on invalid input
	 */
	public static function validate(string $type, array $input): array
	{
		if ($type === 's3') {
			$endpoint = trim((string)($input['endpoint'] ?? ''));
			$bucket = trim((string)($input['bucket'] ?? ''));
			$folder = trim((string)($input['folder'] ?? ''), " /");
			$region = trim((string)($input['region'] ?? ''));
			$accessKey = trim((string)($input['access_key'] ?? ''));
			$secretKey = trim((string)($input['secret_key'] ?? ''));

			if (!preg_match('~^[a-z0-9]([a-z0-9.-]*[a-z0-9])?(:\d{1,5})?$~i', $endpoint)) {
				throw new RuntimeException(Lang::t('The endpoint must be a host name, e.g. s3.example.com (without https://).'));
			}
			if (!preg_match('~^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$~', $bucket)) {
				throw new RuntimeException(Lang::t('The bucket name is invalid (lowercase letters, digits, dot and hyphen only).'));
			}
			if ($folder !== '' && !preg_match('~^[A-Za-z0-9._/-]+$~', $folder)) {
				throw new RuntimeException(Lang::t('The folder in the bucket contains disallowed characters.'));
			}
			if ($region !== '' && !preg_match('~^[a-z0-9-]{2,32}$~', $region)) {
				throw new RuntimeException(Lang::t('The region is invalid.'));
			}
			if (!preg_match('~^[A-Za-z0-9+/=_-]{8,128}$~', $accessKey) || !preg_match('~^[A-Za-z0-9+/=_-]{8,128}$~', $secretKey)) {
				throw new RuntimeException(Lang::t('The access key or secret has an unexpected format.'));
			}

			$env = ['AWS_ACCESS_KEY_ID' => $accessKey, 'AWS_SECRET_ACCESS_KEY' => $secretKey];
			if ($region !== '') {
				$env['AWS_DEFAULT_REGION'] = $region;
			}

			return [
				'repository' => 's3:https://' . $endpoint . '/' . $bucket . ($folder !== '' ? '/' . $folder : ''),
				'env' => $env,
				'label' => 'S3: ' . $bucket . ($folder !== '' ? '/' . $folder : '') . ' @ ' . $endpoint,
				// Without secrets: stored in the settings and displayed.
				'details' => ['type' => 's3', 'endpoint' => $endpoint, 'bucket' => $bucket, 'folder' => $folder, 'region' => $region],
			];
		}

		if ($type === 'sftp') {
			$host = trim((string)($input['host'] ?? ''));
			$path = trim((string)($input['path'] ?? ''));

			// The host is an entry in /root/.ssh/config (with port and key).
			// That way no SSH secret is stored by this extension.
			if (!preg_match('~^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$~', $host)) {
				throw new RuntimeException(Lang::t('The host must be an entry from /root/.ssh/config (letters, digits, dot and hyphen only).'));
			}
			if (!preg_match('~^[A-Za-z0-9._/-]{1,200}$~', $path) || str_contains($path, '..')) {
				throw new RuntimeException(Lang::t('The path on the target server contains disallowed characters.'));
			}

			return [
				'repository' => 'sftp:' . $host . ':' . $path,
				'env' => [],
				'label' => 'SFTP: ' . $host . ':' . $path,
				'details' => ['type' => 'sftp', 'host' => $host, 'path' => $path],
			];
		}

		throw new RuntimeException(Lang::t('Unknown target type.'));
	}

	/**
	 * Writes the environment file and the password file. Root only.
	 *
	 * @param array{repository:string, env:array<string,string>} $target
	 */
	public static function write(array $target, string $password): void
	{
		if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
			throw new RuntimeException(Lang::t('The storage target can only be written as root.'));
		}
		if (strlen($password) < 12 || preg_match('~[\r\n\0]~', $password)) {
			throw new RuntimeException(Lang::t('The encryption password must be at least 12 characters long and must not contain line breaks.'));
		}

		self::createDirectory();

		$lines = ['RESTIC_REPOSITORY=' . $target['repository']];
		foreach ($target['env'] as $name => $value) {
			$lines[] = $name . '=' . $value;
		}

		self::writeSecurely(self::ENV_FILE, implode("\n", $lines) . "\n");
		self::writeSecurely(self::PASSWORD_FILE, $password . "\n");
	}

	/**
	 * Removes credentials and password from the server. The data in the
	 * storage stays, but cannot be opened any more without the password.
	 */
	public static function remove(): void
	{
		if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
			throw new RuntimeException(Lang::t('The storage target can only be removed as root.'));
		}
		foreach ([self::ENV_FILE, self::PASSWORD_FILE] as $file) {
			if ((is_file($file) || is_link($file)) && !@unlink($file)) {
				throw new RuntimeException(Lang::t('Could not delete %s.', $file));
			}
		}
	}

	/**
	 * The existing password (to keep it when only the credentials change).
	 */
	public static function previousPassword(): string
	{
		$p = is_file(self::PASSWORD_FILE) ? trim((string)file_get_contents(self::PASSWORD_FILE)) : '';
		if ($p === '') {
			throw new RuntimeException(Lang::t('There is no previous password that could be kept.'));
		}

		return $p;
	}

	public static function exists(): bool
	{
		return is_file(self::ENV_FILE) && is_file(self::PASSWORD_FILE);
	}

	/**
	 * The environment for restic, read from the file. restic receives it via
	 * proc_open, never via putenv: only the restic processes inherit it.
	 *
	 * @return array<string,string>
	 */
	public static function env(): array
	{
		$content = @file_get_contents(self::ENV_FILE);
		if ($content === false) {
			throw new RuntimeException(Lang::t('No storage target has been set up yet.'));
		}

		$env = [];
		foreach (preg_split('~\R~', $content) as $line) {
			if (preg_match('~^([A-Z][A-Z0-9_]*)=(.*)$~', $line, $t)) {
				$env[$t[1]] = $t[2];
			}
		}
		if (!isset($env['RESTIC_REPOSITORY'])) {
			throw new RuntimeException(Lang::t('The file %s contains no repository.', self::ENV_FILE));
		}
		$env['RESTIC_PASSWORD_FILE'] = self::PASSWORD_FILE;

		return $env;
	}

	private static function createDirectory(): void
	{
		$dir = self::DIRECTORY;
		if (is_link($dir)) {
			throw new RuntimeException(Lang::t('%s is a symbolic link - aborted.', $dir));
		}
		if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
			throw new RuntimeException(Lang::t('Could not create %s.', $dir));
		}
		$stat = lstat($dir);
		if ($stat === false || $stat['uid'] !== 0) {
			throw new RuntimeException(Lang::t('%s is not owned by root - aborted.', $dir));
		}
		@chmod($dir, 0700);
	}

	/**
	 * Writes through a temporary file that has mode 0600 from the start, so
	 * the secret is never readable on disk, and replaces the target
	 * atomically.
	 */
	private static function writeSecurely(string $file, string $content): void
	{
		if (is_link($file)) {
			throw new RuntimeException(Lang::t('%s is a symbolic link - aborted.', $file));
		}

		$old = umask(0077);
		$tmp = $file . '.new';
		try {
			@unlink($tmp);
			$fp = @fopen($tmp, 'xb');
			if ($fp === false) {
				throw new RuntimeException(Lang::t('Could not create %s.', $tmp));
			}
			$ok = fwrite($fp, $content) === strlen($content);
			$ok = fclose($fp) && $ok;
			if (!$ok || !@chmod($tmp, 0600) || !@rename($tmp, $file)) {
				@unlink($tmp);
				throw new RuntimeException(Lang::t('Could not write %s.', $file));
			}
		} finally {
			umask($old);
		}
	}
}
