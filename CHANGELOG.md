# Changelog

## 1.0.1

* Security: an in-place restore backs up the target with all directories above it resolved, and checks afterwards that they still resolve to the same place. If a customer swapped a directory for a link during the backup, the new "pre-restore" snapshot is deleted again and the restore stops before anything is overwritten.

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
