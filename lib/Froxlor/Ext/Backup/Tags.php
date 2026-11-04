<?php

namespace Froxlor\Ext\Backup;

/**
 * The restic tags this extension writes and reads.
 *
 * They are stored inside every snapshot and are the only way the index, the
 * ownership check before a restore and the cleanup ("forget") recognise this
 * extension's snapshots. Changing a value here makes existing snapshots
 * invisible to the panel and exempt from cleanup, so treat them as a storage
 * format, not as labels.
 *
 *   type:customer      one customer (web, mail, database dumps, manifest)
 *   type:system        the server itself (panel database, configuration)
 *   type:pre-restore   the state saved right before a restore overwrote it
 *   customer:<login>   owner of a customer or pre-restore snapshot
 *                      ("_system" for the panel settings)
 *   run:<intermediate|nightly|restore>
 *   without:mail       intermediate snapshot that skipped the mailboxes
 */
final class Tags
{
	public const TYPE_PREFIX = 'type:';
	public const CUSTOMER_PREFIX = 'customer:';
	public const RUN_PREFIX = 'run:';

	public const TYPE_CUSTOMER = 'customer';
	public const TYPE_SYSTEM = 'system';
	public const TYPE_PRE_RESTORE = 'pre-restore';

	public const RUN_INTERMEDIATE = 'intermediate';
	public const RUN_NIGHTLY = 'nightly';
	public const RUN_RESTORE = 'restore';

	public const WITHOUT_MAIL = 'without:mail';

	/** Pseudo customer of the pre-restore snapshot of the panel settings. */
	public const SYSTEM_CUSTOMER = '_system';

	public static function type(string $type): string
	{
		return self::TYPE_PREFIX . $type;
	}

	public static function customer(string $login): string
	{
		return self::CUSTOMER_PREFIX . $login;
	}

	public static function run(string $run): string
	{
		return self::RUN_PREFIX . $run;
	}
}
