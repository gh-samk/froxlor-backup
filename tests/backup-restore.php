<?php

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit(1);
}

/**
 * Checks backup and restore against real restic and a real MariaDB.
 *
 * Runs only as root in a throwaway container: tests/backup-restore.sh sets
 * it up. Do NOT run this on a server with data - the test writes to
 * /etc/froxlor-backup and /var/customers.
 */

$lib = __DIR__ . '/../lib/Froxlor/Ext/Backup/';
require $lib . 'Lang.php';
Froxlor\Ext\Backup\Lang::set('en');

// Instead of panel_settings: the test sets the value itself.
eval('namespace Froxlor\\Ext\\Backup; class BackupSettings { public const GROUP = "ext_backup"; public static string $mailNightlyOnly = "0";'
	. ' public static function get(string $n, string $d = ""): string { return $n === "mail_nightly_only" ? self::$mailNightlyOnly : $d; } }');
// Manifest::FORMAT is needed, the database queries are not.
eval('namespace Froxlor\\Ext\\Backup; class Manifest { public const FORMAT = 1; }');
// Minimal stand-ins for Froxlor's Database and Settings (used by RestoreJob's
// checks of the web copy location), backed by the test database.
eval('namespace Froxlor; class Settings { public static array $values = ["system.documentroot_prefix" => "/var/customers/webs/"];'
	. ' public static function Get(string $k) { return self::$values[$k] ?? null; } }');
eval('namespace Froxlor\\Database; class Database { public static ?\\PDO $pdo = null;'
	. ' public static function prepare(string $q): \\PDOStatement { return self::$pdo->prepare($q); }'
	. ' public static function pexecute(\\PDOStatement &$s, $p = null) { $s->execute($p ?? []); }'
	. ' public static function pexecute_first(\\PDOStatement &$s, $p = null) { $s->execute($p ?? []); return $s->fetch(\\PDO::FETCH_ASSOC); } }');
define('TABLE_PANEL_DOMAINS', 'panel_domains');
define('TABLE_PANEL_CUSTOMERS', 'panel_customers');
foreach (['Tags', 'SecureDir', 'DbConfig', 'Target', 'Restic', 'DbAccess', 'Snapshotter', 'RestoreJob', 'Restorer'] as $class) {
	require $lib . $class . '.php';
}

use Froxlor\Ext\Backup\BackupSettings;
use Froxlor\Ext\Backup\DbAccess;
use Froxlor\Ext\Backup\DbConfig;
use Froxlor\Ext\Backup\Restic;
use Froxlor\Ext\Backup\Restorer;
use Froxlor\Ext\Backup\Snapshotter;
use Froxlor\Ext\Backup\Target;

if (posix_geteuid() !== 0 || getenv('FROXLOR_BACKUP_TESTCONTAINER') !== '1') {
	fwrite(STDERR, "Run only inside the test container (tests/backup-restore.sh).\n");
	exit(2);
}

$failed = 0;
$check = static function (string $name, bool $ok, string $info = '') use (&$failed): void {
	echo ($ok ? '  ok    ' : '  FAIL  ') . $name . ($ok || $info === '' ? '' : "\n        " . $info) . "\n";
	if (!$ok) {
		$failed++;
	}
};
$sh = static fn(string $command): string => (string)shell_exec($command . ' 2>&1');
$throws = static function (callable $f): bool {
	try {
		$f();
		return false;
	} catch (RuntimeException) {
		return true;
	}
};
$snapshots = static fn(array $filter = []): array => json_decode(Restic::run(array_merge(['snapshots', '--json'], $filter), 60)[1], true) ?: [];

// --- Environment ----------------------------------------------------------
$repo = '/tmp/test-repo';
@mkdir(Target::DIRECTORY, 0700, true);
file_put_contents(Target::PASSWORD_FILE, "testpassword-123456\n");
chmod(Target::PASSWORD_FILE, 0600);
file_put_contents(Target::ENV_FILE, "RESTIC_REPOSITORY=$repo\n");
chmod(Target::ENV_FILE, 0600);
[$code] = Restic::run(['init'], 60);
$check('repository created', $code === 0);
$cache = lstat(Restic::CACHE_DIR);
$check('restic cache is root-owned and 0700', $cache !== false && $cache['uid'] === 0 && ($cache['mode'] & 0777) === 0700);

$froxlor = '/tmp/test-froxlor';
@mkdir($froxlor . '/lib', 0755, true);
// Froxlor's layout: socket for the root user, host for the panel user.
file_put_contents($froxlor . '/lib/userdata.inc.php', <<<'PHP'
<?php
$sql = ['host' => 'localhost', 'user' => 'bk', 'password' => 'pa"ss\\word', 'db' => 'froxlor'];
$sql_root = ['0' => ['caption' => 'Default', 'host' => 'localhost', 'socket' => '/run/mysqld/mysqld.sock', 'user' => 'bk', 'password' => 'pa"ss\\word']];
PHP);

$pdo = new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock', 'root', '');
$pdo->exec('DROP DATABASE IF EXISTS customer1sql1');
$pdo->exec('DROP DATABASE IF EXISTS froxlor');
$pdo->exec('CREATE DATABASE customer1sql1 CHARACTER SET utf8mb4');
$pdo->exec('CREATE DATABASE froxlor CHARACTER SET utf8mb4');
$pdo->exec('CREATE TABLE customer1sql1.wp_options (id INT PRIMARY KEY, val TEXT)');
$pdo->exec("INSERT INTO customer1sql1.wp_options VALUES (1, 'Naïve café – 日本語 – ✓'), (2, 'two')");
$pdo->exec('CREATE TABLE customer1sql1.`a b` (id INT)');
$pdo->exec('INSERT INTO customer1sql1.`a b` VALUES (7)');
// Hostile table names: a customer controls them in their own database, and
// root's mariadb-dump must never read them as options.
$pdo->exec('CREATE TABLE customer1sql1.`--result-file=/etc/cron.d/pwn` (a INT)');
$pdo->exec('CREATE TABLE customer1sql1.`-r/tmp/short` (a INT)');
$pdo->exec("CREATE TABLE customer1sql1.`nl` (`a\n* * * * * root touch /tmp/CRON_PWNED\n#` INT)");
$pdo->exec('INSERT INTO customer1sql1.`nl` VALUES (1)');
$pdo->exec("CREATE TABLE customer1sql1.`tab\tname` (a INT)");
$pdo->exec('CREATE TABLE froxlor.panel_settings (settinggroup VARCHAR(20), varname VARCHAR(20), value VARCHAR(20))');
$pdo->exec("INSERT INTO froxlor.panel_settings VALUES ('a', 'b', 'c')");
$pdo->exec("CREATE USER IF NOT EXISTS 'bk'@'localhost' IDENTIFIED BY 'pa\"ss\\\\word'");
// The customer's database user: Froxlor names it like the database and creates
// it only for its access hosts (here: localhost).
$pdo->exec("CREATE USER IF NOT EXISTS 'customer1sql1'@'localhost' IDENTIFIED BY 'x'");
$pdo->exec("GRANT ALL ON `customer1sql1`.* TO 'customer1sql1'@'localhost'");
$pdo->exec("GRANT ALL ON *.* TO 'bk'@'localhost' WITH GRANT OPTION");

$sh('rm -rf /var/customers && mkdir -p /var/customers/webs/customer1/site/wp-content/cache /var/customers/mail/customer1/info/Maildir/cur /var/customers/foreign');
file_put_contents('/var/customers/webs/customer1/site/index.php', '<?php echo "hello";');
file_put_contents('/var/customers/webs/customer1/site/wp-content/cache/x.html', 'cache');
file_put_contents('/var/customers/mail/customer1/info/Maildir/cur/1.eml', 'Subject: Test');
file_put_contents('/var/customers/foreign/secret.txt', 'not mine');
symlink('/var/customers/foreign', '/var/customers/webs/customer1/link-to-foreign');

$base = '/tmp/test-base';
$roots = ['/var/customers/webs', '/var/customers/mail'];

// --- Back up a customer -----------------------------------------------------
$s = new Snapshotter($froxlor, $base . '/' . Snapshotter::WORK_SUBDIR);
$manifest = [
	'format' => 1,
	'customer' => ['login' => 'customer1', 'documentroot' => '/var/customers/webs/customer1/'],
	'mail_dir' => '/var/customers/mail/customer1',
	'databases' => [],
];
$dbs = [
	['id' => 5, 'name' => 'customer1sql1', 'server' => 0, 'description' => 'WP'],
	['id' => 6, 'name' => 'doesnotexist', 'server' => 0, 'description' => ''],
];
$text = implode("\n", $s->customer($manifest, $dbs, 'intermediate', $roots));
$check('customer backed up', str_contains($text, 'customer1: backed up'), $text);
$check('missing database is reported and stops nothing', str_contains($text, 'ERROR: Database doesnotexist'), $text);
$check('staging directory cleaned up', !file_exists($base . '/work/customers/customer1'));
$check('hostile table "--result-file=…" wrote nothing', !file_exists('/etc/cron.d/pwn'));
$check('hostile table "-r…" wrote nothing', !file_exists('/tmp/short'));
$check('hostile table names are skipped with an error line', substr_count($text, 'was skipped') === 3, $text);
$check('no file outside the work directory names a hostile table', trim($sh("grep -rl 'CRON_PWNED' /etc /tmp --include='*' 2>/dev/null | grep -v '^/tmp/test-' || true")) === '');

$snaps = $snapshots();
$check('exactly one snapshot', count($snaps) === 1);
$tags = $snaps[0]['tags'] ?? [];
$check('tags type, customer, run', in_array('type:customer', $tags, true) && in_array('customer:customer1', $tags, true) && in_array('run:intermediate', $tags, true), implode(',', $tags));

$id = $snaps[0]['id'];
[, $list] = Restic::run(['ls', $id], 60);
$check('web file in the snapshot', str_contains($list, '/var/customers/webs/customer1/site/index.php'));
$check('mail file in the snapshot', str_contains($list, '/var/customers/mail/customer1/info/Maildir/cur/1.eml'));
$check('cache excluded', !str_contains($list, 'wp-content/cache/x.html'));
$check('link is not followed', !str_contains($list, 'secret.txt'));
$check('manifest in the snapshot', str_contains($list, $base . '/work/customers/customer1/manifest.json'));
$check('table dump in the snapshot', str_contains($list, 'db/customer1sql1/wp_options.sql.gz') && str_contains($list, 'db/customer1sql1/_schema.sql.gz'));

[, $dumpGz] = Restic::run(['dump', $id, $base . '/work/customers/customer1/db/customer1sql1/wp_options.sql.gz'], 60);
$sql = gzdecode($dumpGz) ?: '';
$check('dump contains the rows (UTF-8)', str_contains($sql, 'Naïve café – 日本語 – ✓') && str_contains($sql, "'two'"));

[, $mj] = Restic::run(['dump', $id, $base . '/work/customers/customer1/manifest.json'], 60);
$m = json_decode($mj, true);
$tables = array_column($m['databases'][0]['tables'] ?? [], 'file', 'name');
$check('manifest knows tables and file names', isset($tables['wp_options']) && str_starts_with($tables['a b'] ?? '', 'table-'), json_encode($tables));
$check('manifest marks the failed database', ($m['databases'][1]['failed'] ?? false) === true);
$check('manifest lists the skipped tables', count($m['databases'][0]['skipped_tables'] ?? []) === 3 && isset($tables['nl']), json_encode($m['databases'][0]['skipped_tables'] ?? null));
[, $nlDump] = Restic::run(['dump', $id, $base . '/work/customers/customer1/db/customer1sql1/nl.sql.gz'], 60);
$check('table with a newline in a column name is dumped normally', str_contains(gzdecode($nlDump) ?: '', 'CRON_PWNED'));

// Reload probe: load the dump into a fresh database.
$pdo->exec('DROP DATABASE IF EXISTS reload');
$pdo->exec('CREATE DATABASE reload');
file_put_contents('/tmp/wp_options.sql', $sql);
$sh('mariadb --user=root reload < /tmp/wp_options.sql');
$check('reloading gives the same rows', (int)$pdo->query('SELECT COUNT(*) FROM reload.wp_options')->fetchColumn() === 2);

// --- Paths outside the allowed roots ------------------------------------------
$evil = $manifest;
$evil['customer']['documentroot'] = '/etc';
$text = implode("\n", $s->customer($evil, [], 'intermediate', $roots));
$check('documentroot /etc is refused', str_contains($text, 'outside the allowed directories'), $text);
$evil['customer']['documentroot'] = '/var/customers/webs';
$text = implode("\n", $s->customer($evil, [], 'intermediate', $roots));
$check('the root itself is refused', str_contains($text, 'outside the allowed directories'), $text);
$evil['customer']['documentroot'] = '/var/customers/webs/customer1/link-to-foreign';
$text = implode("\n", $s->customer($evil, [], 'intermediate', $roots));
$check('link out of the web directory is refused', str_contains($text, 'outside the allowed directories'), $text);

// --- Working directory must not be redirected ---------------------------------
$sh("rm -rf /tmp/evil-work /tmp/evil-target && mkdir -p /tmp/evil-target && ln -s /tmp/evil-target /tmp/evil-work");
$check('symlinked working directory is refused', $throws(fn() => (new Snapshotter($froxlor, '/tmp/evil-work'))->customer($manifest, [], 'intermediate', $roots)));
$check('nothing was written through the link', trim($sh('ls -A /tmp/evil-target')) === '');

// --- System ---------------------------------------------------------------
$text = implode("\n", $s->system('nightly'));
$check('system backed up', str_contains($text, 'System: backed up'), $text);
$sys = $snapshots(['--tag', 'type:system']);
[, $list] = Restic::run(['ls', $sys[0]['id']], 120);
$check('panel table in the system snapshot', str_contains($list, 'work/system/panel-database/panel_settings.sql.gz'));
$check('repository credentials not in the snapshot', !str_contains($list, 'target.env') && !str_contains($list, 'restic-password'));
$check('options files with passwords are not left behind', trim($sh("find $base -name '*.cnf'")) === '');

// --- Cleanup: only own tags, only within the same group -----------------------
$sh('mkdir -p /tmp/group && echo a > /tmp/group/file');
$plain = static fn(array $args) => Restic::run(array_merge(['backup', '--quiet'], $args, ['/tmp/group']), 60);
$plain(['--tag', 'type:customer', '--tag', 'customer:old', '--time', '2026-10-01 00:00:00']);
$plain(['--tag', 'type:customer', '--tag', 'customer:old']);
$plain(['--time', '2026-10-01 00:00:00']); // foreign snapshot without tags (another tool)
$before = count($snapshots());
$message = $s->cleanup(7);
$after = $snapshots();
$check('cleanup reports success', str_contains($message, 'Cleaned up'), $message);
$check('exactly one old point removed', count($after) === $before - 1, "before $before, after " . count($after));
$foreign = array_filter($after, static fn($x) => empty($x['tags']) && str_starts_with($x['time'], '2026-10-01'));
$check('snapshot without tags stays untouched', count($foreign) === 1);

$text = $s->check();
$check('repository check', str_contains($text, 'Repository checked'), $text);

// =========================================================================
// Restore
// =========================================================================
echo "\nRestore\n";
$r = new Restorer($froxlor, $base);
$web = '/var/customers/webs/customer1';
$uid = 1500;
$sh('id -u customer1 >/dev/null 2>&1 || useradd -u 1500 -M -s /usr/sbin/nologin customer1');

// Prepare the current state: special characters in a file name.
file_put_contents($web . '/site/wp-content/a[1].txt', 'original-special');
$s->customer($manifest, $dbs, 'intermediate', $roots);
$points = $snapshots(['--tag', 'customer:customer1', '--tag', 'type:customer']);
usort($points, static fn($a, $b) => strcmp($b['time'], $a['time']));
$point = $points[0]['id'];
$sh("chown -R 1500:1500 $web");

$p = $r->checkPoint($point, 'customer1');
$check('point belongs to customer1', $p['id'] === $point);
$check('foreign customer is rejected', $throws(fn() => $r->checkPoint($point, 'customer2')));
$check('invalid ID is rejected', $throws(fn() => $r->checkPoint('../../x', 'customer1')));
$check('relativePath: .. forbidden', $throws(fn() => Restorer::relativePath('a/../b')) && $throws(fn() => Restorer::relativePath("a\nb")));
$check('relativePath: normalised', Restorer::relativePath('/site/x/') === 'site/x' && Restorer::relativePath('') === '');

$c = $r->contents($point, "$web/site");
$check('preview counts files', $c['files'] >= 2, json_encode($c));
$check('preview: unknown path reports an error', $throws(fn() => $r->contents($point, "$web/doesnotexist")));

$file = static fn(string $source, string $target, int $job, string $mode = 'in_place', bool $before = true, ?string $root = null) => $r->restoreFiles([
	'id' => $point, 'login' => 'customer1', 'source' => $source, 'target' => $target,
	'uid' => $uid, 'gid' => $uid, 'mode' => $mode, 'save_before' => $before, 'root' => $root ?? (str_starts_with($target, '/var/customers/restore/') ? '/var/customers/restore/customer1' : $web), 'job' => $job,
]);

// Break a file, add a new file, then restore a single file.
file_put_contents("$web/site/index.php", 'BROKEN');
file_put_contents("$web/site/new-after-backup.txt", 'stays');
$z = $file("$web/site/index.php", "$web/site/index.php", 1);
$check('single file restored', file_get_contents("$web/site/index.php") === '<?php echo "hello";', implode(' | ', $z));
$check('owner is the customer', fileowner("$web/site/index.php") === $uid);
$check('state before was saved (pre-restore)', str_contains(implode(' ', $z), 'pre-restore'));
$pre = $snapshots(['--tag', 'type:pre-restore']);
$check('pre-restore snapshot with customer tag', count($pre) === 1 && in_array('customer:customer1', $pre[0]['tags'], true) && in_array('run:restore', $pre[0]['tags'], true));
$check('staging area cleaned up', !file_exists($base . '/restore/1'));
$st = lstat($base . '/restore');
$check('staging root is root-owned, 0711', $st !== false && $st['uid'] === 0 && ($st['mode'] & 0777) === 0711);

// Folder: overwrite contents, new files stay.
file_put_contents("$web/site/index.php", 'BROKEN AGAIN');
$file("$web/site", "$web/site", 2);
$check('folder restore overwrites', file_get_contents("$web/site/index.php") === '<?php echo "hello";');
$check('new file stays', file_get_contents("$web/site/new-after-backup.txt") === 'stays');

// Special characters in the file name (restic pattern).
file_put_contents("$web/site/wp-content/a[1].txt", 'broken');
$file("$web/site/wp-content/a[1].txt", "$web/site/wp-content/a[1].txt", 3);
$check('file with [1] in its name', file_get_contents("$web/site/wp-content/a[1].txt") === 'original-special');

// Copy: nothing is overwritten.
file_put_contents("$web/site/index.php", 'TODAY');
$file("$web/site", "$web/_restored/t1", 4, 'copy');
$check('copy lands in the target folder', file_get_contents("$web/_restored/t1/index.php") === '<?php echo "hello";');
$check('copy leaves the original untouched', file_get_contents("$web/site/index.php") === 'TODAY');

// Undo: the pre-restore state of test 1 (file was "BROKEN").
usort($pre, static fn($a, $b) => strcmp($a['time'], $b['time']));
$r->restoreFiles(['id' => $pre[0]['id'], 'login' => 'customer1', 'source' => $pre[0]['paths'][0], 'target' => $pre[0]['paths'][0],
	'uid' => $uid, 'gid' => $uid, 'mode' => 'in_place', 'root' => $web, 'job' => 5]);
$check('undo brings back the state before the restore', file_get_contents("$web/site/index.php") === 'BROKEN');

// Security: target outside, link in the target, too small UID.
$check('target outside the root refused', $throws(fn() => $file("$web/site/index.php", '/etc/passwd', 6)));
$sh("ln -sfn /var/customers/foreign $web/linktarget");
$check('target through a link out of the root refused', $throws(fn() => $file("$web/site/index.php", "$web/linktarget/index.php", 7)));
$check('foreign directory untouched', !file_exists('/var/customers/foreign/index.php'));
$check('UID below 1000 refused', $throws(fn() => $r->restoreFiles(['id' => $point, 'login' => 'customer1',
	'source' => "$web/site/index.php", 'target' => "$web/site/index.php", 'uid' => 0, 'gid' => 0, 'mode' => 'in_place', 'root' => $web, 'job' => 8])));

// Database
$access = DbAccess::load($froxlor, 0);
$check('database preview names tables', in_array('wp_options', $r->databasePreview($point, 'customer1', 'customer1sql1', null), true));
$check('preview: unknown table', $throws(fn() => $r->databasePreview($point, 'customer1', 'customer1sql1', 'doesnotexist')));
$check('preview: database with error', $throws(fn() => $r->databasePreview($point, 'customer1', 'doesnotexist', null)));

$pdo->exec('DROP TABLE customer1sql1.wp_options');
$z = $r->restoreDatabase($point, 'customer1', 'customer1sql1', 'wp_options', 'in_place', true, $access, 10);
$check('dropped table comes back', (int)$pdo->query('SELECT COUNT(*) FROM customer1sql1.wp_options')->fetchColumn() === 2, implode(' | ', $z));

$preCount = count($snapshots(['--tag', 'type:pre-restore']));
$pdo->exec("UPDATE customer1sql1.wp_options SET val = 'changed' WHERE id = 1");
$pdo->exec("INSERT INTO customer1sql1.wp_options VALUES (3, 'new')");
$z = $r->restoreDatabase($point, 'customer1', 'customer1sql1', 'wp_options', 'in_place', true, $access, 11);
$check('table is replaced', (int)$pdo->query('SELECT COUNT(*) FROM customer1sql1.wp_options')->fetchColumn() === 2
	&& $pdo->query('SELECT val FROM customer1sql1.wp_options WHERE id = 1')->fetchColumn() === 'Naïve café – 日本語 – ✓');
$check('current state of the table was saved', str_contains(implode(' ', $z), 'pre-restore'));
$check('exactly one new pre-restore point for the table', count($snapshots(['--tag', 'type:pre-restore'])) === $preCount + 1);

$z = $r->restoreDatabase($point, 'customer1', 'customer1sql1', null, 'copy', true, $access, 12);
$check('copy: tables in customer1sql1_restore', (int)$pdo->query('SELECT COUNT(*) FROM customer1sql1_restore.wp_options')->fetchColumn() === 2
	&& (int)$pdo->query('SELECT COUNT(*) FROM customer1sql1_restore.`a b`')->fetchColumn() === 1, implode(' | ', $z));
$grants = implode(' ', $pdo->query("SHOW GRANTS FOR 'customer1sql1'@'localhost'")->fetchAll(PDO::FETCH_COLUMN));
$check('copy: the customer database user may access the copy', str_contains($grants, '`customer1sql1_restore`') && str_contains(implode(' ', $z), 'has access'), $grants . ' | ' . implode(' | ', $z));
$check('database staging area cleaned up', !file_exists($base . '/db-restore/10') && !file_exists($base . '/db-restore/before-11'));

// Without saving the state first: nothing extra is stored.
$preCount = count($snapshots(['--tag', 'type:pre-restore']));
file_put_contents("$web/site/index.php", 'WHATEVER');
$z = $file("$web/site/index.php", "$web/site/index.php", 20, 'in_place', false);
$check('file restored without saving first', file_get_contents("$web/site/index.php") === '<?php echo "hello";' && !str_contains(implode(' ', $z), 'pre-restore'), implode(' | ', $z));
$pdo->exec("UPDATE customer1sql1.wp_options SET val = 'whatever' WHERE id = 1");
$z = $r->restoreDatabase($point, 'customer1', 'customer1sql1', 'wp_options', 'in_place', false, $access, 21);
$check('table restored without saving first', $pdo->query('SELECT val FROM customer1sql1.wp_options WHERE id = 1')->fetchColumn() === 'Naïve café – 日本語 – ✓' && !str_contains(implode(' ', $z), 'pre-restore'), implode(' | ', $z));
$check('no new pre-restore points', count($snapshots(['--tag', 'type:pre-restore'])) === $preCount);

// Panel settings from the system snapshot (temporary database).
$settings = $r->readSettings($sys[0]['id'], $access, 30);
$check('settings read from the system point', ($settings['a.b'] ?? null) === 'c', json_encode($settings));
$check('temporary database dropped', (int)$pdo->query("SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = 'froxlor_restore_30'")->fetchColumn() === 0);

// Intermediate backup without mailboxes.
BackupSettings::$mailNightlyOnly = '1';
$s->customer($manifest, [], 'intermediate', $roots);
$s->customer($manifest, [], 'nightly', $roots);
$all = $snapshots(['--tag', 'customer:customer1,type:customer']);
usort($all, static fn($a, $b) => strcmp($b['time'], $a['time']));
[$nightly, $intermediate] = [$all[0], $all[1]];
$check('intermediate point carries without:mail', in_array('without:mail', $intermediate['tags'], true) && in_array('run:intermediate', $intermediate['tags'], true));
$check('intermediate point contains no mails', !str_contains(Restic::run(['ls', $intermediate['id']], 60)[1], '/var/customers/mail/'));
$check('intermediate point contains the website', str_contains(Restic::run(['ls', $intermediate['id']], 60)[1], '/var/customers/webs/customer1/site/index.php'));
$check('nightly point contains mails and no without:mail', !in_array('without:mail', $nightly['tags'], true)
	&& str_contains(Restic::run(['ls', $nightly['id']], 60)[1], '/var/customers/mail/customer1/info/Maildir/cur/1.eml'));

// --- Customer processes: no inherited descriptors, scrubbed environment, cwd / -
$asUser = new ReflectionMethod(Restorer::class, 'asUser');
$asUser->setAccessible(true);
$sh('rm -f /tmp/probe-*');
$check('the test itself holds an open database socket', str_contains($sh('ls -l /proc/' . getmypid() . '/fd'), 'socket:'));
$asUser->invoke($r, $uid, $uid, ['/bin/sh', '-c', 'ls -l /proc/$$/fd > /tmp/probe-fds; env > /tmp/probe-env; pwd > /tmp/probe-cwd; id -u > /tmp/probe-uid']);
$fdList = (string)@file_get_contents('/tmp/probe-fds');
preg_match_all('~ (\d+) -> (.*)$~m', $fdList, $fdm, PREG_SET_ORDER);
$foreign = array_filter($fdm, static fn($x) => !preg_match('~^(/dev/null|pipe:.*|/tmp/probe-fds)$~', $x[2]));
$check('customer process inherits no root descriptor (no DB socket, no files)', $fdm !== [] && $foreign === [], $fdList);
$env = array_filter(explode("\n", trim((string)@file_get_contents('/tmp/probe-env'))));
$names = array_map(static fn($l) => explode('=', $l, 2)[0], $env);
sort($names);
$check('customer process gets only the allowlisted environment', array_values(array_diff($names, ['PWD', 'SHLVL', '_'])) === ['HOME', 'LC_ALL', 'PATH'], implode(',', $names));
$check('customer process starts in / as the customer', trim((string)@file_get_contents('/tmp/probe-cwd')) === '/' && trim((string)@file_get_contents('/tmp/probe-uid')) === (string)$uid);

// --- Full database restore: DEFINERs can never end up as root -----------------
$pdo->exec("CREATE USER IF NOT EXISTS 'root'@'%' IDENTIFIED BY 'unused-x9'");
$pdo->exec("GRANT ALL ON *.* TO 'root'@'%'");
$pdo->exec("CREATE DEFINER=`root`@`%` PROCEDURE customer1sql1.evil() SQL SECURITY DEFINER SELECT 1");
$pdo->exec("CREATE DEFINER=`root`@`%` SQL SECURITY DEFINER VIEW customer1sql1.evil_view AS SELECT 1 AS x");
$pdo->exec("CREATE DEFINER=`root`@`localhost` TRIGGER customer1sql1.evil_trg BEFORE INSERT ON customer1sql1.wp_options FOR EACH ROW SET NEW.val = NEW.val");
$s->customer($manifest, $dbs, 'intermediate', $roots);
$latest = $snapshots(['--tag', 'customer:customer1', '--tag', 'type:customer']);
usort($latest, static fn($a, $b) => strcmp($b['time'], $a['time']));
$pdo->exec('DROP DATABASE customer1sql1');
$pdo->exec('CREATE DATABASE customer1sql1 CHARACTER SET utf8mb4');
$z = $r->restoreDatabase($latest[0]['id'], 'customer1', 'customer1sql1', null, 'in_place', false, $access, 40);
$definers = $pdo->query("SELECT DEFINER FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = 'customer1sql1'
	UNION ALL SELECT DEFINER FROM information_schema.VIEWS WHERE TABLE_SCHEMA = 'customer1sql1'
	UNION ALL SELECT DEFINER FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = 'customer1sql1'")->fetchAll(PDO::FETCH_COLUMN);
$check('full restore: all objects back, every DEFINER is the database user', count($definers) === 3
	&& count(array_filter($definers, static fn($d) => str_starts_with($d, 'customer1sql1@'))) === 3, implode(', ', $definers) . ' | ' . implode(' | ', $z));
$check('full restore: the user exists for the chosen host, so view and procedure work (no ERROR 1449)',
	(int)$pdo->query('SELECT x FROM customer1sql1.evil_view')->fetchColumn() === 1 && $pdo->query('CALL customer1sql1.evil()') !== false
	&& count(array_filter($definers, static fn($d) => $d === 'customer1sql1@localhost')) === 3, implode(', ', $definers));
$pdo->exec("INSERT INTO customer1sql1.wp_options VALUES (9, 'trigger ok')");
$check('full restore: the trigger works', $pdo->query('SELECT val FROM customer1sql1.wp_options WHERE id = 9')->fetchColumn() === 'trigger ok');
$pdo->exec('DELETE FROM customer1sql1.wp_options WHERE id = 9');
$check('full restore: data back', (int)$pdo->query('SELECT COUNT(*) FROM customer1sql1.wp_options')->fetchColumn() === 2, implode(' | ', $z));
$check('rewriteDefiner leaves no root definer', !str_contains(DbAccess::rewriteDefiner("/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */ CREATE DEFINER='root'@'%' PROCEDURE", 'customer1sql1'), 'root'));

// --- Web copies go outside every document root ---------------------------------
\Froxlor\Database\Database::$pdo = new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=froxlor', 'root', '');
\Froxlor\Database\Database::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE IF NOT EXISTS froxlor.panel_domains (id INT, documentroot VARCHAR(255))');
$pdo->exec('CREATE TABLE IF NOT EXISTS froxlor.panel_customers (customerid INT, documentroot VARCHAR(255))');
$pdo->exec("INSERT INTO froxlor.panel_domains VALUES (1, '/var/customers/webs/customer1/'), (2, '/var/customers/webs/customer1/site/'), (3, 'https://redirect.example.com/')");
$pdo->exec("INSERT INTO froxlor.panel_customers VALUES (1, '/var/customers/webs/customer1/')");
$copyRoot = \Froxlor\Ext\Backup\RestoreJob::webCopyRoot('customer1');
$check('web copy root is next to the web tree', $copyRoot === '/var/customers/restore/customer1', $copyRoot);
$check('copy root outside every document root is accepted', !$throws(fn() => \Froxlor\Ext\Backup\RestoreJob::assertNotServed($copyRoot)));
$check('copy location inside a document root is refused', $throws(fn() => \Froxlor\Ext\Backup\RestoreJob::assertNotServed('/var/customers/webs/customer1/_restored')));
$check('copy location around a document root is refused', $throws(fn() => \Froxlor\Ext\Backup\RestoreJob::assertNotServed('/var/customers/webs')));
$prepare = new ReflectionMethod(\Froxlor\Ext\Backup\RestoreJob::class, 'prepareWebCopyRoot');
$prepare->setAccessible(true);
$prepare->invoke(null, $copyRoot, $uid, $uid);
$st = lstat($copyRoot);
$pt = lstat(dirname($copyRoot));
$check('copy root: customer-owned 0700 below a root-owned 0711 parent', $st['uid'] === $uid && ($st['mode'] & 0777) === 0700 && $pt['uid'] === 0 && ($pt['mode'] & 0777) === 0711);
$file("$web/site", "$copyRoot/t2/site", 41, 'copy');
$check('web copy lands outside the web tree', file_get_contents("$copyRoot/t2/site/index.php") === '<?php echo "hello";');

// --- Client options: TCP, TLS lines accepted by the MariaDB client tools -----
$tcp = DbConfig::normalize(['host' => '127.0.0.1', 'port' => '3306', 'user' => 'bk', 'password' => 'pa"ss\\word']);
file_put_contents('/tmp/tcp.cnf', implode("\n", DbConfig::clientOptions($tcp)) . "\n");
$check('client connects over host and port from the options file', trim($sh('mariadb --defaults-extra-file=/tmp/tcp.cnf -N -e "SELECT 42"')) === '42');
$check('PDO connects over host and port', (int)DbConfig::pdo($tcp)->query('SELECT 42')->fetchColumn() === 42);
foreach ([true, false] as $verify) {
	$tls = DbConfig::normalize(['host' => '127.0.0.1', 'user' => 'bk', 'password' => 'x', 'ssl' => ['caFile' => '/tmp/ca.pem', 'verifyServerCertificate' => $verify]]);
	file_put_contents('/tmp/tls.cnf', implode("\n", DbConfig::clientOptions($tls)) . "\n");
	$out = $sh('mariadb-dump --defaults-extra-file=/tmp/tls.cnf --print-defaults; echo "exit=$?"');
	$check('mariadb-dump accepts the TLS options (verify ' . ($verify ? 'on' : 'off') . ')', str_contains($out, '--ssl-ca=/tmp/ca.pem') && str_contains($out, 'exit=0'), $out);
}

echo $failed === 0 ? "\nAll checks passed.\n" : "\n$failed check(s) failed.\n";
exit($failed === 0 ? 0 : 1);
