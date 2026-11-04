#!/usr/bin/env bash
#
# Removes the Backup extension from a Froxlor installation.
#
#   ./uninstall.sh /var/www/froxlor            remove the files only
#   ./uninstall.sh /var/www/froxlor --purge    additionally drop the tables and settings
#
# Without --purge the job history and the settings stay, so a temporary
# removal loses nothing.
#
# Never removed: /etc/froxlor-backup (credentials and encryption password),
# the backups in the storage, and /var/lib/froxlor-backup.

set -euo pipefail
umask 022

TARGET="${1:-}"
PURGE=0
[[ "${2:-}" == "--purge" ]] && PURGE=1

if [[ -z "$TARGET" ]] || [[ ! -f "$TARGET/lib/init.php" ]]; then
	echo "Usage: ./uninstall.sh <froxlor-directory> [--purge]" >&2
	exit 1
fi
TARGET="$(cd "$TARGET" && pwd -P)"

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
# shellcheck source=scripts/privileges.sh
. "$HERE/scripts/privileges.sh"

FILES=(
	"admin_backup.php"
	"actions/admin/settings/191.ext.backup.php"
	"lib/navigation/51.ext.backup.php"
	"lib/Froxlor/Ext/Backup"
	"templates/ExtBackup"
)

# Removals inside the Froxlor directory run as its owner (scripts/privileges.sh).
WRITER="$(id -un)"
setup_writer "$TARGET" "$HERE" "uninstall.sh" "${FILES[@]}" "cache"
check_no_links "$TARGET" "${FILES[@]}" "cache"

echo "Removing files (as $WRITER)"
for path in "${FILES[@]}"; do
	if [[ -e "$TARGET/$path" || -L "$TARGET/$path" ]]; then
		as_owner rm -rf -- "$TARGET/$path" </dev/null
		echo "  removed: $path"
	fi
done
# lib/Froxlor/Ext/ is shared with other extensions; remove it only when empty.
if [[ -d "$TARGET/lib/Froxlor/Ext" && -z "$(ls -A "$TARGET/lib/Froxlor/Ext" 2>/dev/null)" ]]; then
	as_owner rmdir -- "$TARGET/lib/Froxlor/Ext" </dev/null || true
fi

if [[ -d "$TARGET/cache" ]]; then
	as_owner find "$TARGET/cache" -mindepth 1 -maxdepth 1 -type d -exec rm -rf -- {} + </dev/null 2>/dev/null || true
fi

echo
echo "Database"
USERDATA="$TARGET/lib/userdata.inc.php"
if command -v php >/dev/null 2>&1 && [[ ! -L "$USERDATA" && -f "$USERDATA" && -r "$USERDATA" ]]; then
	OPTS=()
	[[ $PURGE -eq 1 ]] && OPTS+=(--purge)
	php "$HERE/migrations/remove.php" "$TARGET" "${OPTS[@]+"${OPTS[@]}"}" </dev/null 2>&1 | sanitize_output
else
	echo "  Credentials not readable (or a link) or php missing - please run by hand:" >&2
	echo "    DELETE FROM cronjobs_run WHERE module = 'ext/backup';" >&2
fi

echo
if [[ -d /etc/froxlor-backup ]]; then
	echo "Kept: /etc/froxlor-backup (credentials and encryption password)."
	echo "Store the password elsewhere before deleting it - without it the backups cannot be opened."
fi
echo "Done."
