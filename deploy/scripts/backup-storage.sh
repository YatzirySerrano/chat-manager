#!/usr/bin/env bash
# Chat Manager — backup of the private files (WhatsApp attachments, team chat
# files, imports) with checksum and rotation. Generated exports are excluded
# (they expire after EXPORTS_RETENTION_HOURS and can be generated again);
# logs and framework caches too. The .env file is NOT included: keep it in a
# password manager or secret store, it holds APP_KEY (needed to decrypt the
# connection credentials stored in the database).
#
# Runs as root: install a reviewed copy owned by root, never run it from the
# releases (they belong to the deploy user, see deploy/sudoers/chat-manager):
#   sudo install -o root -g root -m 0755 deploy/scripts/backup-storage.sh /usr/local/sbin/chat-manager-backup-storage
set -euo pipefail

STORAGE_DIR="${STORAGE_DIR:-/var/www/chat-manager/shared/storage}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/chat-manager/storage}"
RETENTION_DAYS="${RETENTION_DAYS:-14}"

umask 077
mkdir -p "$BACKUP_DIR"
stamp="$(date -u +%Y%m%dT%H%M%SZ)"
target="$BACKUP_DIR/storage-$stamp.tar.gz"

tar --create --gzip --file "$target.partial" \
    --directory "$STORAGE_DIR" \
    --exclude='app/private/exports' \
    --exclude='framework' \
    --exclude='logs' \
    app

gzip -t "$target.partial"
mv "$target.partial" "$target"
(cd "$BACKUP_DIR" && sha256sum "$(basename "$target")" > "$(basename "$target").sha256")

find "$BACKUP_DIR" -name 'storage-*.tar.gz*' -type f -mtime +"$RETENTION_DAYS" -delete

echo "Storage backup OK: $target ($(du -h "$target" | cut -f1))"
