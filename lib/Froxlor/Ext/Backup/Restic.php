<?php

namespace Froxlor\Ext\Backup;

use RuntimeException;

/**
 * Thin wrapper around the restic binary. Used only in the cron, as root.
 *
 * Called without a shell (argument list passed to proc_open): there is
 * nothing a form value could turn into a command. Credentials reach the
 * restic process only through its environment, never as an argument.
 */
class Restic
{
	/** @var string[] */
	public const CANDIDATES = ['/usr/bin/restic', '/usr/local/bin/restic', '/opt/restic/restic'];

	/** restic's local cache. */
	public const CACHE_DIR = '/var/cache/froxlor-backup';

	public static function path(): string
	{
		foreach (self::CANDIDATES as $path) {
			if (is_file($path) && is_executable($path)) {
				return $path;
			}
		}
		throw new RuntimeException(Lang::t('restic is not installed (searched: %s).', implode(', ', self::CANDIDATES)));
	}

	public static function installed(): bool
	{
		foreach (self::CANDIDATES as $path) {
			if (is_executable($path)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param string[] $args e.g. ['snapshots', '--json']
	 * @param bool $gentle with nice and ionice: a backup should not slow down
	 *                     websites and mailboxes
	 * @return array{0:int, 1:string, 2:string} exit code, stdout, stderr
	 */
	public static function run(array $args, int $timeout = 300, bool $gentle = false): array
	{
		$env = Target::env();
		// restic needs PATH and HOME for ssh (SFTP) and its cache.
		$env['PATH'] = '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';
		$env['HOME'] = '/root';
		$env['RESTIC_CACHE_DIR'] = self::CACHE_DIR;
		if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
			SecureDir::ensure(self::CACHE_DIR, 0700);
		}

		$command = array_merge([self::path()], $args);
		if ($gentle) {
			if (is_executable('/usr/bin/ionice')) {
				$command = array_merge(['/usr/bin/ionice', '-c', '2', '-n', '7'], $command);
			}
			if (is_executable('/usr/bin/nice')) {
				$command = array_merge(['/usr/bin/nice', '-n', '10'], $command);
			}
		}

		$process = @proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, '/', $env);
		if (!is_resource($process)) {
			throw new RuntimeException(Lang::t('restic could not be started.'));
		}

		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);
		$out = '';
		$err = '';
		$deadline = time() + $timeout;

		while (true) {
			$read = [$pipes[1], $pipes[2]];
			$write = $except = null;
			if (@stream_select($read, $write, $except, 1) > 0) {
				foreach ($read as $r) {
					$chunk = (string)fread($r, 65536);
					if ($r === $pipes[1]) {
						$out .= $chunk;
					} else {
						$err .= $chunk;
					}
				}
			}

			$status = proc_get_status($process);
			if (!$status['running']) {
				$out .= (string)stream_get_contents($pipes[1]);
				$err .= (string)stream_get_contents($pipes[2]);
				break;
			}
			if (time() > $deadline || strlen($out) > 64 * 1024 * 1024) {
				proc_terminate($process, 15);
				usleep(500000);
				proc_terminate($process, 9);
				fclose($pipes[1]);
				fclose($pipes[2]);
				proc_close($process);
				throw new RuntimeException(Lang::t('restic was aborted after %d seconds.', $timeout));
			}
		}

		fclose($pipes[1]);
		fclose($pipes[2]);
		$code = proc_close($process);
		// After proc_get_status, proc_close often returns -1; the status before counts.
		if ($code === -1 && isset($status['exitcode']) && $status['exitcode'] >= 0) {
			$code = $status['exitcode'];
		}

		return [$code, $out, $err];
	}

	/**
	 * Error output for the log: credentials from the environment are
	 * redacted in case a service echoes them in a message.
	 */
	public static function redact(string $text): string
	{
		try {
			foreach (Target::env() as $name => $value) {
				if ($value !== '' && (str_contains($name, 'SECRET') || str_contains($name, 'KEY')) && str_contains($text, $value)) {
					$text = str_replace($value, '***', $text);
				}
			}
		} catch (RuntimeException) {
			// No environment, nothing to redact.
		}

		return $text;
	}
}
