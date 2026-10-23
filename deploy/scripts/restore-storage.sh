#!/usr/bin/env bash
# Chat Manager — restore a storage backup (storage/app) made by backup-storage.sh.
#
# Usage: chat-manager-restore-storage <storage-AAAAMMDDTHHMMSSZ.tar.gz>
#
# Runs as root: install a reviewed copy owned by root, never run it from the
# releases (they belong to the deploy user, see deploy/sudoers/chat-manager):
#   sudo install -o root -g root -m 0755 deploy/scripts/restore-storage.sh /usr/local/sbin/chat-manager-restore-storage
#
# Safe by default: verifies the checksum (required unless
# ALLOW_NO_CHECKSUM=1) and the archive, extracts it into a temporary
# directory next to the storage, asks to type "restaurar", and then swaps
# directories atomically: the current storage/app is KEPT as
# app.before-restore-<timestamp> (delete it once the restore is verified).
# Generated exports are not in the backup; the current ones are carried over.
# Stop the workers and enable maintenance mode first
# (docs/recuperacion-y-respaldos.md §6.2).
#
# Variables: STORAGE_DIR (default /var/www/chat-manager/shared/storage),
# RESTORE_OWNER (default www-data:www-data).
set -euo pipefail

backup="${1:?backup file required}"
STORAGE_DIR="${STORAGE_DIR:-/var/www/chat-manager/shared/storage}"
RESTORE_OWNER="${RESTORE_OWNER:-www-data:www-data}"

[ -r "$backup" ] || { echo "Cannot read $backup" >&2; exit 1; }
[ -d "$STORAGE_DIR" ] || { echo "Missing storage directory $STORAGE_DIR" >&2; exit 1; }

if [ -f "$backup.sha256" ]; then
    (cd "$(dirname "$backup")" && sha256sum -c "$(basename "$backup").sha256")
elif [ "${ALLOW_NO_CHECKSUM:-0}" = "1" ]; then
    echo "WARNING: no checksum file next to the backup (ALLOW_NO_CHECKSUM=1)" >&2
else
    echo "No checksum file $backup.sha256. Set ALLOW_NO_CHECKSUM=1 to restore anyway." >&2
    exit 1
fi

gzip -t "$backup"

# Same filesystem as the storage, so the final swap is a rename.
staging="$(mktemp -d "$STORAGE_DIR/.restore-XXXXXX")"
trap 'rm -rf "$staging"' EXIT

tar --extract --gzip --file "$backup" --directory "$staging" --no-same-owner --no-same-permissions app
[ -d "$staging/app" ] || { echo "The archive does not contain app/." >&2; exit 1; }

echo "Archive contents: $(find "$staging/app" -type f | wc -l) files, $(du -sh "$staging/app" | cut -f1)."
echo "Current storage:  $(find "$STORAGE_DIR/app" -type f 2>/dev/null | wc -l) files."
read -r -p "Type \"restaurar\" to replace $STORAGE_DIR/app with the backup: " confirmation
[ "$confirmation" = "restaurar" ] || { echo "Cancelled." >&2; exit 1; }

previous="$STORAGE_DIR/app.before-restore-$(date -u +%Y%m%dT%H%M%SZ)"

if [ -d "$STORAGE_DIR/app/private/exports" ] && [ ! -e "$staging/app/private/exports" ]; then
    mkdir -p "$staging/app/private"
    cp -a "$STORAGE_DIR/app/private/exports" "$staging/app/private/exports"
fi

chown -R "$RESTORE_OWNER" "$staging/app"
chmod -R u+rwX,g+rwX,o-rwx "$staging/app"
find "$staging/app" -type d -exec chmod g+s {} +

if [ -d "$STORAGE_DIR/app" ]; then
    mv "$STORAGE_DIR/app" "$previous"
fi
mv "$staging/app" "$STORAGE_DIR/app"

echo "Restored $backup into $STORAGE_DIR/app."
if [ -d "$previous" ]; then
    echo "Previous files kept in $previous (delete it once the restore is verified)."
fi
