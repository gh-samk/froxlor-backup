<?php

namespace Froxlor\Ext\Backup;

use RuntimeException;

/**
 * Directories that root writes into (working, staging and cache areas).
 *
 * Each must be a real directory - not a symbolic link -, belong to the
 * executing user and not be writable by group or others before anything is
 * written into it. Otherwise someone who could plant a link or a file there
 * could redirect root's writes.
 */
final class SecureDir
{
	public static function ensure(string $path, int $mode = 0700): void
	{
		if (is_link($path)) {
			throw new RuntimeException(Lang::t('%s is a symbolic link - aborted.', $path));
		}
		if (!is_dir($path) && !@mkdir($path, $mode, true) && !is_dir($path)) {
			throw new RuntimeException(Lang::t('Could not create %s.', $path));
		}
		$stat = @lstat($path);
		$uid = function_exists('posix_geteuid') ? posix_geteuid() : null;
		if ($stat === false || ($stat['mode'] & 0170000) !== 0040000 || ($uid !== null && $stat['uid'] !== $uid)) {
			throw new RuntimeException(Lang::t('%s does not belong to the executing user or is writable by others - aborted.', $path));
		}
		if (($stat['mode'] & 07777) !== $mode && !@chmod($path, $mode)) {
			throw new RuntimeException(Lang::t('%s does not belong to the executing user or is writable by others - aborted.', $path));
		}
	}
}
