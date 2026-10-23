#!/usr/bin/env bash
# Chat Manager — consistent MariaDB backup with checksum and rotation.
#
# Runs as root: install a reviewed copy owned by root, never run it from the
# releases (they belong to the deploy user, see deploy/sudoers/chat-manager):
#   sudo install -o root -g root -m 0755 deploy/scripts/backup-database.sh /usr/local/sbin/chat-manager-backup-database
#
# Credentials are read from an option file, never from the command line:
#   /etc/chat-manager/backup.cnf  (chmod 600, owner root)
#     [client]
#     user=chatmanager_backup
#     password=<secret>
#     host=127.0.0.1
# The backup user needs: SELECT, SHOW VIEW, TRIGGER, EVENT, LOCK TABLES on the
# application database (no write privileges).
#
# --single-transaction gives a consistent snapshot of InnoDB tables without
# locking the application. Files: <dir>/<db>-<UTC timestamp>.sql.gz + .sha256
set -euo pipefail

DB_NAME="${DB_NAME:-chatmanager}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/chat-manager/database}"
DEFAULTS_FILE="${DEFAULTS_FILE:-/etc/chat-manager/backup.cnf}"
RETENTION_DAYS="${RETENTION_DAYS:-14}"
DUMP="$(command -v mariadb-dump || command -v mysqldump)"

[ -r "$DEFAULTS_FILE" ] || { echo "Cannot read $DEFAULTS_FILE" >&2; exit 1; }

umask 077
mkdir -p "$BACKUP_DIR"
stamp="$(date -u +%Y%m%dT%H%M%SZ)"
target="$BACKUP_DIR/$DB_NAME-$stamp.sql.gz"
partial="$target.partial"

"$DUMP" --defaults-extra-file="$DEFAULTS_FILE" \
    --single-transaction --quick --routines --triggers --events --hex-blob \
    --default-character-set=utf8mb4 --no-tablespaces \
    "$DB_NAME" | gzip -9 > "$partial"

gzip -t "$partial"
mv "$partial" "$target"
(cd "$BACKUP_DIR" && sha256sum "$(basename "$target")" > "$(basename "$target").sha256")

find "$BACKUP_DIR" -name "$DB_NAME-*.sql.gz*" -type f -mtime +"$RETENTION_DAYS" -delete

echo "Backup OK: $target ($(du -h "$target" | cut -f1))"
