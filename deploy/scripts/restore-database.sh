#!/usr/bin/env bash
# Chat Manager — restore a database backup into a target database.
#
# Usage: deploy/scripts/restore-database.sh <backup.sql.gz> <target_database>
#
# Safe by default: verifies the checksum, refuses a target that already has
# tables unless ALLOW_NON_EMPTY=1, and asks to type the database name. To
# replace production, first restore into a scratch database (e.g.
# chatmanager_restore), verify it, then follow docs/recuperacion-y-respaldos.md.
# Credentials: /etc/chat-manager/restore.cnf (a user with CREATE/INSERT...).
set -euo pipefail

backup="${1:?backup file required}"
target="${2:?target database required}"
DEFAULTS_FILE="${DEFAULTS_FILE:-/etc/chat-manager/restore.cnf}"
CLIENT="$(command -v mariadb || command -v mysql)"

[ -r "$backup" ] || { echo "Cannot read $backup" >&2; exit 1; }
[ -r "$DEFAULTS_FILE" ] || { echo "Cannot read $DEFAULTS_FILE" >&2; exit 1; }

if [ -f "$backup.sha256" ]; then
    (cd "$(dirname "$backup")" && sha256sum -c "$(basename "$backup").sha256")
else
    echo "WARNING: no checksum file next to the backup" >&2
fi

gzip -t "$backup"

"$CLIENT" --defaults-extra-file="$DEFAULTS_FILE" -e "CREATE DATABASE IF NOT EXISTS \`$target\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
tables="$("$CLIENT" --defaults-extra-file="$DEFAULTS_FILE" -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$target'")"

if [ "$tables" != "0" ] && [ "${ALLOW_NON_EMPTY:-0}" != "1" ]; then
    echo "Database $target already has $tables tables. Set ALLOW_NON_EMPTY=1 to overwrite." >&2
    exit 1
fi

read -r -p "Type the target database name ($target) to restore into it: " confirmation
[ "$confirmation" = "$target" ] || { echo "Cancelled." >&2; exit 1; }

gunzip -c "$backup" | "$CLIENT" --defaults-extra-file="$DEFAULTS_FILE" "$target"

echo "Restored $backup into $target."
"$CLIENT" --defaults-extra-file="$DEFAULTS_FILE" -N -e "SELECT COUNT(*) AS migrations FROM \`$target\`.migrations"
