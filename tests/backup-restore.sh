#!/usr/bin/env bash
#
# Starts a throwaway container (Debian 13) with restic and MariaDB and runs
# tests/backup-restore.php inside it.
#
#   tests/backup-restore.sh
#
# Needs Docker and internet access (package installation). Nothing on the
# host is changed; the container is removed afterwards.

set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
IMAGE="${BACKUP_TEST_IMAGE:-debian:trixie}"

docker run --rm -v "$HERE:/src:ro" -e FROXLOR_BACKUP_TESTCONTAINER=1 "$IMAGE" bash -c '
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq >/dev/null
apt-get install -y -qq php-cli php-mysql restic mariadb-server mariadb-client >/dev/null 2>&1
mkdir -p /run/mysqld && chown mysql /run/mysqld
mariadbd-safe --user=mysql >/dev/null 2>&1 &
for i in $(seq 1 30); do mariadb-admin ping --silent 2>/dev/null && break; sleep 1; done
php -d display_errors=1 -d error_reporting=-1 /src/tests/backup-restore.php
'
