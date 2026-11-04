#!/usr/bin/env bash
#
# Installs the Backup extension into an existing Froxlor 2.x installation.
#
#   ./install.sh /var/www/froxlor
#   ./install.sh /var/www/froxlor --dry-run          write nothing, only show
#   ./install.sh /var/www/froxlor --skip-migrations  files only, no database
#
# Only new files are copied - no Froxlor file is changed. Run this script
# again after every Froxlor update.
#
# Run as root, writes into the Froxlor directory happen as the owner of that
# directory (see scripts/privileges.sh), so the panel user cannot redirect
# them with planted links.

set -euo pipefail
umask 022

TARGET="${1:-}"
shift || true

DRY_RUN=0
MIGRATE=1
for opt in "$@"; do
	case "$opt" in
		--dry-run)         DRY_RUN=1 ;;
		--skip-migrations) MIGRATE=0 ;;
		*) echo "Unknown option: $opt" >&2; exit 1 ;;
	esac
done

if [[ -z "$TARGET" ]]; then
	cat >&2 <<'USAGE'
Usage: ./install.sh <froxlor-directory> [--dry-run] [--skip-migrations]

Example:
    ./install.sh /var/www/froxlor
USAGE
	exit 1
fi

if [[ ! -f "$TARGET/lib/init.php" ]] || [[ ! -d "$TARGET/templates/Froxlor" ]]; then
	echo "ERROR: '$TARGET' does not look like a Froxlor installation." >&2
	exit 1
fi
TARGET="$(cd "$TARGET" && pwd -P)"

if ! command -v php >/dev/null 2>&1; then
	echo "ERROR: php not found." >&2
	exit 1
fi
PHP_ID="$(php -r 'echo PHP_VERSION_ID;' 2>/dev/null || echo 0)"
if [[ ! "$PHP_ID" =~ ^[0-9]+$ ]] || (( PHP_ID < 80100 )); then
	echo "ERROR: PHP 8.1 or newer is required (found $(php -r 'echo PHP_VERSION;' 2>/dev/null || echo unknown))." >&2
	exit 1
fi

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
VERSION="$(cat "$HERE/VERSION" 2>/dev/null || echo "unknown")"
# shellcheck source=scripts/privileges.sh
. "$HERE/scripts/privileges.sh"

# Exactly these paths belong to the extension. Listed one by one instead of a
# blanket sync: that way the script can never overwrite or delete anything
# that belongs to Froxlor.
FILES=(
	"admin_backup.php"
	"actions/admin/settings/191.ext.backup.php"
	"lib/navigation/51.ext.backup.php"
	"lib/Froxlor/Ext/Backup/"
	"templates/ExtBackup/"
)
RELS=()
for path in "${FILES[@]}"; do
	if [[ ! -e "$HERE/${path%/}" ]] || [[ -L "$HERE/${path%/}" ]]; then
		echo "ERROR: $path is missing from the package." >&2
		exit 1
	fi
	RELS+=("${path%/}")
done

echo "Backup $VERSION"
echo "Target: $TARGET"
[[ $DRY_RUN -eq 1 ]] && echo "(dry run, nothing is written)"

WRITER="$(id -un)"
setup_writer "$TARGET" "$HERE" "install.sh" "${RELS[@]}" "cache"
echo "Writing as: $WRITER"
# Check every path before writing anything, so a refusal leaves no half install.
check_no_links "$TARGET" "${RELS[@]}" "cache"
echo

# Copied with tar instead of rsync: tar is part of every base system, rsync
# is often missing on slim servers.
echo "Files"
for path in "${FILES[@]}"; do
	if [[ $DRY_RUN -eq 1 ]]; then
		echo "  would copy: $path"
		continue
	fi
	# Directories are replaced as a whole, so files removed in a newer
	# version do not linger.
	if [[ "$path" == */ ]]; then
		as_owner rm -rf -- "$TARGET/${path%/}" </dev/null
	fi
	as_owner mkdir -p -- "$(dirname "$TARGET/${path%/}")" </dev/null
	( cd "$HERE" && tar cf - "${path%/}" ) \
		| as_owner tar xf - -C "$TARGET" --no-same-owner --no-same-permissions
	echo "  $path"
done

# Twig caches compiled templates; without clearing, a change would run
# against the old compiled version.
if [[ $DRY_RUN -eq 0 && -d "$TARGET/cache" ]]; then
	as_owner find "$TARGET/cache" -mindepth 1 -maxdepth 1 -type d -exec rm -rf -- {} + </dev/null 2>/dev/null || true
	echo "  Twig cache cleared"
fi

if [[ $MIGRATE -eq 0 ]]; then
	echo
	echo "Database skipped (--skip-migrations)."
	echo "Apply the migrations later with:"
	echo "    php $HERE/migrations/apply.php $TARGET"
	exit 0
fi

echo
echo "Database"

# Through PHP/PDO instead of a MySQL command line client: PHP exists on every
# Froxlor server, a mysql client not necessarily. It uses exactly the
# connection options from Froxlor's userdata.inc.php, including TLS. The file
# is parsed, never executed (migrations/connection.php).
USERDATA="$TARGET/lib/userdata.inc.php"
if [[ -L "$USERDATA" ]] || [[ ! -f "$USERDATA" ]] || [[ ! -r "$USERDATA" ]]; then
	echo "  ERROR: $USERDATA is not a readable regular file." >&2
	echo "  Run the script as root, or use --skip-migrations." >&2
	exit 1
fi

MIG_OPTS=()
[[ $DRY_RUN -eq 1 ]] && MIG_OPTS+=(--dry-run)
# Output may quote parts of the panel user's file: strip control characters.
php "$HERE/migrations/apply.php" "$TARGET" "${MIG_OPTS[@]+"${MIG_OPTS[@]}"}" </dev/null 2>&1 | sanitize_output

echo
echo "Done. The backup appears in the admin area under \"Backup\" (admins with"
echo "the right to change server settings only)."
echo
echo "Required programs on the server:"
missing=0
for prog in restic setpriv timeout; do
	if command -v "$prog" >/dev/null 2>&1; then
		echo "  ok       $prog"
	else
		echo "  MISSING  $prog"
		missing=1
	fi
done
if command -v mariadb-dump >/dev/null 2>&1 || command -v mysqldump >/dev/null 2>&1; then
	echo "  ok       mariadb-dump/mysqldump"
else
	echo "  MISSING  mariadb-dump or mysqldump"
	missing=1
fi
[[ $missing -eq 1 ]] && echo "  On Debian: apt install restic util-linux coreutils mariadb-client"
echo "  Then enter the storage target in the panel under Backup > Setup."
echo
echo "To verify that no Froxlor file was touched:"
echo "    cd $TARGET && git status"
