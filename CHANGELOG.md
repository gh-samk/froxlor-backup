# Changelog

## 1.0.0

Initial public release.

* restic backups of every customer (web, mail, each database table) and of
  the server itself, on a configurable schedule with retention, cleanup and
  repository checks.
* Restores from the panel: websites, folders, mailboxes, databases and single
  tables, in place (with automatic pre-restore snapshot and undo) or as a
  copy; restore of the panel settings.
* S3-compatible object storage or SFTP as storage target; credentials only
  in `/etc/froxlor-backup`.
* English and German interface.
* Optional integration for other extensions (`ProjectViewIntegration`).
