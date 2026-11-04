# Backup for Froxlor 2.x

Encrypted, deduplicated backups of every customer of a
[Froxlor](https://froxlor.org) server with [restic](https://restic.net) -
and restores from the panel: whole websites, single folders, mailboxes,
databases or single tables, as of any restore point, in place or as a copy.

**Version 1.0.1** - for Froxlor 2.3.x (developed against 2.3.13, checked
against 2.3.16), prepared for a future 2.4 that keeps the 2.x architecture.

## What it does

A new admin menu entry **Backup** (only for admins with the right to change
server settings) with four pages:

* **Overview** - last backup, number of restore points, reachability of the
  storage target, retention; which customers are covered; the latest
  snapshots; buttons for "Back up now", "Refresh list", "Check connection".
* **Restore** - pick a customer, then tick per project (a main domain with
  its subdomains) what should come back: websites, mailboxes, databases,
  optionally a single folder or a single table. Choose a restore point, look
  at the preview, confirm. Plus: restore the **server settings**
  (`panel_settings`) to an earlier state, and **undo** an earlier restore.
* **History** - every job with its log.
* **Setup** - storage target (S3-compatible object storage or SFTP) and
  encryption password.

**What is backed up** - per customer: the web directory, the mail directory
and every database (structure plus each table as a separate dump, so single
tables can be restored), together with a manifest of what belonged to the
customer. In the nightly run additionally the server itself: the panel
database, `/etc`, the Froxlor directory, Let's Encrypt and acme.sh data and
the rspamd DKIM keys.

**Schedule (default)** - intermediate backups at 00:00, 08:00, 12:00 and
18:00, the nightly backup at 01:00 with cleanup and a sample check of the
repository (`restic check --read-data-subset=2%`). All restore points of the
last 7 days are kept. Mailboxes go into the nightly backup only (every run
walks through all mails); intermediate restore points are then marked
"without mailboxes". Everything is adjustable under Settings > Backup. Thanks
to deduplication the storage grows with the changes, not with the number of
backups.

**Restores** run as the customer (never as root) and only inside that
customer's own directories. Files are added and overwritten, nothing is
deleted: files created after the restore point stay. "In place" first saves
the current state as a `pre-restore` snapshot (which "Undo" brings back; the
checkbox "Back up the current state first" can be cleared). "As a copy"
overwrites nothing:

* website files land in `/var/customers/restore/<login>/<timestamp>/` (next
  to the web tree, derived from Froxlor's documentroot prefix), owned by the
  customer with mode 0700. Never below the customer's web directory: that is
  the document root of the standard subdomain and of subdomains without a
  path of their own, so restored files such as `wp-config.php` would be
  downloadable. A copy location that is, contains or lies inside any
  document root known to Froxlor is refused. The admin fetches the files
  from there (or moves them where the customer can reach them);
* mailboxes land in `_restored/<timestamp>/` inside the customer's mail
  directory (not served by any web server);
* databases land in `<name>_restore`, and the customer's database user gets
  the same access to it as to the original.

The extension is available in English and German (see
[Languages](#languages)).

## The key point: nothing is patched

The extension consists **exclusively of new files**. No Froxlor file is
changed, so a Froxlor update cannot overwrite it - Froxlor does not know
these files. It uses only stable hook points of Froxlor 2.x:

| What | How | Where in Froxlor |
|---|---|---|
| Menu entry | every `*.php` in `lib/navigation/` is merged | `lib/init.php` -> `PhpHelper::loadConfigArrayDir` |
| Settings | every `*.php` in `actions/admin/settings/` is merged | `admin_settings.php` |
| Cron job | row in `cronjobs_run`, class loaded by name | `lib/Froxlor/Cli/MasterCron.php` |
| Classes | Composer PSR-4 maps `Froxlor\` to `lib/Froxlor/` | `composer.json` |
| Templates | the Twig loader is rooted in `templates/` | `lib/Froxlor/UI/Panel/UI.php` |
| Stylesheet | `templates/` is served by the web server, included via `<link>` | - |

Installed into the Froxlor directory:

```
admin_backup.php
actions/admin/settings/191.ext.backup.php
lib/navigation/51.ext.backup.php
lib/Froxlor/Ext/Backup/          (namespace Froxlor\Ext\Backup)
templates/ExtBackup/             (no config.json: not a theme)
```

## Requirements

* Froxlor 2.3.x (2.x architecture), PHP 8.1 or newer (the installer checks
  this), with `pdo_mysql`.
* Froxlor's cron runs as root (the default) - the jobs need that to read all
  customer data and to restore as the respective customer.
* On the server: `restic`, `setpriv` (util-linux), `timeout` (coreutils),
  `mariadb-dump` or `mysqldump` and the `mariadb`/`mysql` client. On Debian:
  `apt install restic util-linux coreutils mariadb-client`. The installer
  reports what is missing. `doveadm` is used if present, so restored mails
  show up immediately.
* A storage target: an S3-compatible bucket (endpoint, bucket, access key,
  secret key) or an SFTP server configured as a host entry in
  `/root/.ssh/config` (with port and key; no SSH secret is stored by this
  extension). One storage location is enough.

## Installation

Clone the repository **outside any web root** (for example under `/root` or
`/usr/local/src`), then run the installer as root:

```bash
git clone https://github.com/lichtsignale-sk/froxlor-backup.git /usr/local/src/froxlor-backup
cd /usr/local/src/froxlor-backup
./install.sh /var/www/froxlor
```

The script copies the files, clears the Twig cache and applies the database
migration. It reads the database credentials from Froxlor's own
`lib/userdata.inc.php`, so there is nothing to configure twice. It connects
through PHP/PDO with the same options as Froxlor (socket or host and port,
TLS); no MySQL command line client is needed for that.

To see first what would happen:

```bash
./install.sh /var/www/froxlor --dry-run
```

Files only, database later:

```bash
./install.sh /var/www/froxlor --skip-migrations
php migrations/apply.php /var/www/froxlor
```

Afterwards: **Backup > Setup** in the admin area. Enter the storage target
and leave the password field empty - the panel generates a strong encryption
password and shows it **exactly once** in the history. Store it in your
password manager right away: without it, the backups cannot be opened. To
attach an existing restic repository, enter its password instead.

The cron picks the setup up within a minute, writes the credentials to
`/etc/froxlor-backup`, opens the repository (or creates it if it does not
exist yet - never when access is merely denied or the password is wrong)
and reads the existing restore points.

### Update

The same again - the migration is idempotent:

```bash
git pull && ./install.sh /var/www/froxlor
```

**Run `install.sh` again after every Froxlor update.** An update cannot
overwrite the files, but an update that replaces the whole directory removes
unknown files. The re-run is cheap.

### Uninstall

```bash
./uninstall.sh /var/www/froxlor           # files and cron job gone, history and settings stay
./uninstall.sh /var/www/froxlor --purge   # additionally drop the tables and settings
```

Never removed: `/etc/froxlor-backup` (credentials and encryption password),
the backups in the storage and `/var/lib/froxlor-backup`. Store the password
elsewhere before you delete `/etc/froxlor-backup` - without it the backups
cannot be opened.

## Configuration

Under **Settings > Backup**:

| Setting | Default | Meaning |
|---|---|---|
| Intermediate backups per day | `00:00,08:00,12:00,18:00` | times (server time), up to 8 |
| Nightly backup | `01:00` | adds server backup, cleanup and repository check |
| Mailboxes only in the nightly backup | on | intermediate restore points without mailboxes |
| Retention in days | `7` | restore points older than this are removed at night; align an object lock period with it |

The storage target is deliberately not a setting: credentials do not belong
in the panel database. It is changed under **Backup > Setup** ("Change
credentials or target"; with "Keep the current password and repository" all
existing backups stay usable) and can be removed there (typed confirmation;
the backups in the storage stay but cannot be opened without the password).

A scheduled backup is caught up for up to three hours after its time if the
cron was busy; each slot creates exactly one job.

## Security model

* **The panel never runs restic and never sees credentials.** Pages only
  create jobs in `ext_backup_jobs` and read the cache `ext_backup_index`.
  Everything else runs in Froxlor's cron as root
  (`Cron/BackupJobCron.php`), one job at a time.
* **Credentials live only in `/etc/froxlor-backup`** (directory 0700, files
  0600, owned by root; symlinks and foreign owners are refused; written via
  a temporary file and an atomic rename). The panel database holds them only
  between submitting the setup form and the cron taking the job over (at
  most about a minute): the column `secret_params` is cleared at takeover and
  is never read by any page. Secrets are removed from the form data before
  an error page could store it in the session.
* **restic gets its secrets through its environment**, never as arguments;
  everything is called without a shell (argument lists). Error output is
  redacted before it goes into a job log.
* **`userdata.inc.php` is never executed by this extension.** It belongs to
  the panel's PHP user, while the installer, the migrations and the cron
  read it as root. It is tokenized instead, and only plain literal
  assignments (the formats Froxlor writes) are accepted; anything else -
  function calls, interpolation, constants, a symlinked file - is refused.
* **Customer-chosen names never become options.** Every `mariadb-dump` and
  `mariadb` call ends its options with `--` before the database and table
  names, and table names that start with `-` or contain control characters
  are skipped (with an error line in the job log and in the manifest)
  instead of being passed on at all. Such a run ends as "done with
  warnings", not as failed.
* **No object is ever recreated with root as its definer.** A full database
  restore imports the structure dump (views, routines, triggers, events)
  through the server's root account, so every `DEFINER` clause is rewritten
  to the database's own user (Froxlor names it like the database), on a host
  that user really exists for (the original host if it exists, else
  `localhost`, else the first one in `mysql.user`); afterwards
  any object of the database that still has another definer is dropped and
  reported. Triggers are dumped separately as well and re-applied after the
  table data, because importing a table dump drops the table's triggers.
* **Customer processes** (copying restored files, cleaning up the staging
  area) start through `setpriv` with no supplementary groups and
  `--no-new-privs`, an `env -i` allowlist (`PATH`, `HOME`, `LC_ALL`), stdin
  from `/dev/null`, working directory `/`, and without any of root's open
  file descriptors - the cron holds Froxlor's root database connection.
  Paths are absolute and resolved by the customer process with the
  customer's rights; root never changes into a customer directory.
* **Restore copies are never web-accessible** (see "As a copy" above).
* **Stale restic locks** (left by a process that died) are removed with
  `restic unlock` before cleanup and check; locks of running processes stay.
* **Database passwords** reach `mariadb-dump`/`mariadb` only through a
  temporary options file (0600, created exclusively) in a root-only
  directory; it is deleted right after use.
* **Backup paths** come from Froxlor and must resolve (all links followed)
  below the configured web or mail root; a wrongly set documentroot such as
  `/` or `/etc` is skipped. restic does not follow symlinks.
* **Restores run as the customer.** restic restores as root into a root-owned
  staging directory; it is then handed to the customer, and copying into the
  customer's directory as well as cleaning up the staging area happen with
  `setpriv` as the customer (UID/GID below 1000 are refused, `--no-new-privs`,
  `timeout`). A planted symlink therefore cannot make root write or delete
  anywhere. Targets are checked against the customer's directory at the
  deepest existing path component. Restore paths typed by the admin may not
  contain `..`, `.` or control characters.
* **Ownership is checked twice**: every restore point is checked against the
  index and again against restic's own tags before anything is restored;
  domains, mailboxes and databases are checked against the customer in
  Froxlor's tables.
* **Typed confirmation** with the customer name (or `settings`, `remove`) for
  everything that overwrites or deletes, checked on the server. Forms use
  Froxlor's CSRF token.
* **Working directories** that root writes into (`/var/lib/froxlor-backup`,
  `/var/cache/froxlor-backup`) must be real directories owned by root and
  not writable by others.
* **The installer and uninstaller** (`scripts/privileges.sh`): run as root,
  they refuse to run from a package directory that another user can change,
  resolve the Froxlor directory with `pwd -P`, and refuse any symbolic link
  on the target paths. If the Froxlor directory belongs to the panel user,
  every write and delete inside it runs as that user via
  `setsid --wait setpriv --reuid --regid --init-groups --reset-env` - a new
  session without a controlling terminal (no TIOCSTI keystroke injection
  into root's terminal), stdin from `/dev/null`, no inherited descriptors
  above 2, output stripped of control characters. Root writes itself only
  if every component from `/` down is root-owned, not group/other-writable
  and not a link. `umask 022`, no ownership or permissions taken from the
  archive. A symlinked `userdata.inc.php` is refused, and the output of the
  migration helpers is stripped of control characters as well.
* Only admins with `change_serversettings` see the menu entry; the page
  checks it again.

## Design decisions

* **One restic snapshot per customer**, tagged (`type:customer`,
  `customer:<login>`, `run:<intermediate|nightly>`, `without:mail`); one for
  the server (`type:system`); `type:pre-restore` for states saved right
  before a restore. Cleanup (`forget --prune --keep-within`) only touches
  snapshots with these tags, so a repository that is also used by another
  tool keeps its other snapshots. The tags and the staging paths
  (`/var/lib/froxlor-backup/work/customers/<login>/…`,
  `…/work/system/panel-database/…`) are recorded inside every snapshot and
  form the storage format; see `Tags.php` and `Snapshotter.php`.
* **Each table is dumped separately** (`--single-transaction`, `--hex-blob`,
  utf8mb4) plus a structure dump with views, triggers, routines and events.
  That is what makes single-table restores possible. Unusual table names get
  a hashed file name; the manifest maps them.
* **The index is a cache.** The cron refreshes it hourly and after every
  backup and restore. Page views stay fast and work even when the storage
  is unreachable.
* **Repository creation is conservative.** A repository is only initialised
  when restic explicitly reports a missing config file - never on "access
  denied", a timeout or a wrong password, which would otherwise silently
  create a second, empty repository.
* **Restoring panel settings** loads the `panel_settings` dump into a
  temporary database, compares, shows every difference (secret-looking
  values hidden) and writes only differing values. Versions, counters, last
  run times and this extension's own settings are never turned back. Froxlor
  then rebuilds its configuration (task 1).
* **No JavaScript, Vue stays out.** Froxlor mounts Vue on `<body>`; the
  content is wrapped in `v-pre`, and the stylesheet is a separate file linked
  with its modification time as version (Vue drops `<style>` blocks, Froxlor
  sends no cache headers for static files).
* **Project grouping without other extensions.** Projects are main domains
  with their subdomains and mailboxes, straight from Froxlor's tables.
  Froxlor does not link databases to domains, so databases appear under
  "Without a project" - unless an extension provides the assignment through
  the optional hook described below.

## Working with other extensions

Backup works on its own. Two optional, one-directional interfaces exist:

* **Provided:** `Froxlor\Ext\Backup\ProjectViewIntegration` - for a backup
  box on a project page:

  ```php
  ProjectViewIntegration::status(string $customerLogin, array $userinfo): array
  // ['configured' => bool, 'last_backup' => ?int, 'stale' => bool, 'allowed' => bool]
  ProjectViewIntegration::restoreParams(string $customerLogin, int $domainId): array
  // ['page' => 'restore', 'customer' => …, 'project' => …, 'scope' => 'all']
  ```

  `stale` means no backup within 26 hours, `allowed` means the admin has
  `change_serversettings`. `scope` preselects what is ticked for the
  project: `all`, `web`, `mail` or `db`. Callers check
  `class_exists(ProjectViewIntegration::class)` first.

* **Consumed if present:**
  `Froxlor\Ext\ProjectView\BackupIntegration::resourceAssignments(int $customerId): array`
  returning `[['domainid' => int, 'type' => 'database'|'ftp', 'id' => int], …]`.
  If it exists, the restore page groups databases under their project and
  the manifest records the assignment (`ProjectAssignments.php`). The result
  is validated: malformed entries are ignored, and any error makes Backup
  behave as if the provider were not installed.

## Languages

All texts exist in English and German. The user's language applies (Froxlor
profile, otherwise the panel default): German for `de*`/"Deutsch", English
for anything else. Without a session (cron jobs) the panel default language
applies; job logs are stored in that language.

Froxlor's own language files stay untouched. The class `Lang` brings its own
catalog: the English source text is the key (`Lang::t('Text')` in PHP,
`{{ backup_t('Text') }}` in Twig), German is in
`lib/Froxlor/Ext/Backup/lang/de.php`.

## Layout

```
admin_backup.php                       the page (overview, restore, history, setup)
actions/admin/settings/191.ext.backup.php
lib/navigation/51.ext.backup.php
lib/Froxlor/Ext/Backup/
  Cron/BackupJobCron.php               runs the jobs (root), plans the schedule
  JobQueue.php                         ext_backup_jobs
  SnapshotIndex.php                    ext_backup_index (cache of restic snapshots)
  BackupRun.php                        one run across all customers
  Snapshotter.php                      customer and system snapshots, cleanup, check
  Manifest.php                         what belongs to a customer
  RestoreJob.php                       resolves a restore job against Froxlor
  Restorer.php                         files and databases back from a snapshot
  Restic.php, Target.php, Tags.php     restic, storage target, snapshot tags
  DbAccess.php, DbConfig.php           credentials from userdata.inc.php, dumps
  SecureDir.php                        root-owned working directories
  Schedule.php, BackupSettings.php     schedule, state in panel_settings
  ProjectViewIntegration.php           contract for other extensions
  ProjectAssignments.php               optional database-to-project assignment
  Lang.php, lang/de.php                English/German
templates/ExtBackup/                   Twig templates and stylesheet
migrations/                            apply.php, remove.php, connection.php, 001_backup.php
scripts/privileges.sh                  privilege drop and path checks for install/uninstall
```

Data:

| Where | What |
|---|---|
| `ext_backup_jobs` | job queue and history (last 100 finished jobs) |
| `ext_backup_index` | cache of the snapshots |
| `panel_settings`, group `ext_backup` | settings and state |
| `cronjobs_run`, module `ext/backup` | cron job `ext_backup_jobs`, every minute |
| `/etc/froxlor-backup/target.env`, `restic-password` | credentials (root only) |
| `/var/lib/froxlor-backup/` | `work/` (staging for snapshots), `restore/`, `db-restore/` |
| `/var/cache/froxlor-backup/` | restic cache |
| `/var/customers/restore/<login>/` | website copies ("restore as a copy"), customer-owned, 0700 |

## Deliberately not included

* Re-creating a deleted customer. Recreate the customer in Froxlor with the
  same login (and its domains, mailboxes and databases), then restore here.
* Triggers after a single-table restore: importing one table recreates the
  table and drops its triggers (as with any `mysqldump` table import); a
  full database restore brings them back. Copies (`<name>_restore`) contain
  tables only.
* An "Undo" button for databases. The current state is still saved as a
  `pre-restore` snapshot before overwriting and can be fetched with restic.
* More than one storage target.

## License

GPL-2.0-or-later, like Froxlor. See [LICENSE](LICENSE).
Copyright (C) Samuel Kümmel.
