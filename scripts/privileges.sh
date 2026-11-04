# shellcheck shell=bash
#
# Privilege helpers, sourced by install.sh and uninstall.sh.
#
# The Froxlor directory usually belongs to the panel's system user, and that
# user can plant symbolic links or files there. When the scripts run as root,
# nothing that user controls may influence root:
#
#   - inside the Froxlor directory, root works as its owner (drop_to): in a new
#     session without a controlling terminal (no TIOCSTI keystroke injection
#     into root's terminal), with a reset environment, without root's file
#     descriptors above 2, stdin from /dev/null unless a pipe is passed, and
#     with all output stripped of control characters;
#   - root writes itself only where nobody else can: every component from /
#     down is owned by root, not group/other-writable and not a link
#     (untrusted_component);
#   - no target path component may be a symbolic link (check_no_links).

# Owner and group of a path itself (a link is not followed).
file_uid() { stat -c %u "$1" 2>/dev/null || stat -f %u "$1"; }
file_gid() { stat -c %g "$1" 2>/dev/null || stat -f %g "$1"; }

# Strips control characters (except tab and newline) from a stream that may
# contain text chosen by the panel user before it reaches root's terminal.
sanitize_output() { LC_ALL=C tr -d '\000-\010\013-\037\177'; }

# untrusted_component PATH [UID...]
# Prints the first component of PATH, walking up to /, that root must not
# trust: a symbolic link, a missing path, a file or directory owned by anyone
# but root (or the given UIDs), or one writable by others or by a group other
# than root's. Directories with the sticky bit (like /tmp) are accepted.
# Prints nothing if all are fine.
untrusted_component() {
	local path="$1" uid
	shift
	local owners=(! -user 0)
	for uid in "$@"; do
		[[ -n "$uid" ]] && owners+=(! -user "$uid")
	done
	while :; do
		if [[ -L "$path" ]] || [[ ! -e "$path" ]] \
			|| [[ -n "$(find "$path" -maxdepth 0 \( \( "${owners[@]}" \) \
				-o \( -perm -0002 ! -perm -1000 \) \
				-o \( -perm -0020 ! -group 0 ! -perm -1000 \) \) -print 2>/dev/null)" ]]; then
			printf '%s\n' "$path"
			return 0
		fi
		[[ "$path" == "/" ]] && return 0
		path="$(dirname "$path")"
	done
}

# Closes every file descriptor above 2 of the current (sub)shell.
close_extra_fds() {
	local fd
	[[ -d "/proc/$BASHPID/fd" ]] || return 0
	for fd in "/proc/$BASHPID/fd/"*; do
		fd="${fd##*/}"
		if [[ "$fd" =~ ^[0-9]+$ ]] && (( fd > 2 )); then
			eval "exec $fd>&-" 2>/dev/null || true
		fi
	done
}

# drop_to UID GID CMD...
# Runs CMD as that user (see the header). stdin is whatever the caller
# passes - use </dev/null unless CMD reads a pipe.
drop_to() {
	local uid="$1" gid="$2"
	shift 2
	if ! command -v setsid >/dev/null 2>&1 || ! command -v setpriv >/dev/null 2>&1; then
		echo "ERROR: setsid and setpriv (util-linux) are required to drop root privileges safely." >&2
		return 1
	fi
	(
		close_extra_fds
		# Never start the new user in the caller's directory (usually /root): it
		# cannot enter it, and tools like find fail on their initial directory.
		cd / || exit 1
		exec setsid --wait setpriv --reuid="$uid" --regid="$gid" --init-groups --reset-env -- "$@"
	) 2>&1 | sanitize_output
}

# check_no_links ROOT REL...
# Exits if any existing component of ROOT/REL below ROOT is a symbolic link,
# or if an existing directory REL contains one. Nothing in this package is a
# link, so any link there was put there by someone else.
check_no_links() {
	local root="$1" rel current part found
	local -a parts
	shift
	for rel in "$@"; do
		current="$root"
		IFS=/ read -ra parts <<< "$rel"
		for part in "${parts[@]}"; do
			[[ -z "$part" ]] && continue
			current="$current/$part"
			if [[ -L "$current" ]]; then
				echo "ERROR: $current is a symbolic link - aborted." >&2
				exit 1
			fi
			[[ -e "$current" ]] || break
		done
		if [[ -d "$root/$rel" ]]; then
			found="$(find "$root/$rel" -type l -print -quit 2>/dev/null)"
			if [[ -n "$found" ]]; then
				echo "ERROR: symbolic link found at $found - aborted." >&2
				exit 1
			fi
		fi
	done
}

# setup_writer ROOT HERE SCRIPT [DIR...]
# Decides who writes into the Froxlor directory ROOT and defines as_owner.
# As root: the package itself (SCRIPT) must not be changeable by others; if
# ROOT belongs to another user, as_owner drops to that user; if it belongs to
# root, every DIR (and everything above) must be trusted.
as_owner() { "$@"; }
setup_writer() {
	local root="$1" here="$2" script="$3" dir bad
	shift 3
	[[ $EUID -eq 0 ]] || return 0
	bad="$(untrusted_component "$here/$script" "${SUDO_UID:-}")"
	if [[ -n "$bad" ]]; then
		echo "ERROR: the package is not safe to run as root: $bad is a link or" >&2
		echo "owned/writable by another user. Clone it outside any web root (e.g. /usr/local/src)." >&2
		exit 1
	fi
	OWNER_UID="$(file_uid "$root")"
	OWNER_GID="$(file_gid "$root")"
	if [[ "$OWNER_UID" != "0" ]]; then
		as_owner() { drop_to "$OWNER_UID" "$OWNER_GID" "$@"; }
		WRITER="uid $OWNER_UID (owner of $root)"
		return 0
	fi
	for dir in "$@"; do
		# The deepest existing directory on the way, then everything above it.
		while [[ "$dir" != "." && ! -e "$root/$dir" ]]; do dir="$(dirname "$dir")"; done
		bad="$(untrusted_component "$root/$dir")"
		if [[ -z "$bad" && -d "$root/$dir" ]]; then
			bad="$(find "$root/$dir" -type d \( ! -user 0 -o -perm -0002 -o \( -perm -0020 ! -group 0 \) \) -print -quit 2>/dev/null)"
		fi
		if [[ -n "$bad" ]]; then
			echo "ERROR: $root belongs to root, but $bad is a link or owned/writable by" >&2
			echo "another user or group. Root does not write where a less privileged user" >&2
			echo "can plant links. Give the Froxlor directory to the panel's system user" >&2
			echo "(the scripts then write as that user), or make every directory on the" >&2
			echo "way owned by root and not group/world-writable." >&2
			exit 1
		fi
	done
	WRITER="root ($root is root-owned and not writable by others)"
}
