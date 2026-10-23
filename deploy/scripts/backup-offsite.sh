#!/usr/bin/env bash
# Chat Manager — copy the local backups (database and storage, with their
# .sha256 files) to storage outside this server. A backup that only lives on
# the same VPS is lost with it.
#
# Runs as root: install a reviewed copy owned by root, never run it from the
# releases (they belong to the deploy user, see deploy/sudoers/chat-manager):
#   sudo install -o root -g root -m 0755 deploy/scripts/backup-offsite.sh /usr/local/sbin/chat-manager-backup-offsite
#
# Variables:
#   OFFSITE_TARGET  required; rsync destination (<user>@<backup-host>:<path>)
#                   or rclone remote (<remote>:<bucket>/<path>), configured
#                   beforehand by the administrator (SSH key or rclone.conf
#                   of root; no credential goes here).
#   OFFSITE_TOOL    rsync (default) or rclone.
#   BACKUP_ROOT     default /var/backups/chat-manager.
#
# Files are only added, never deleted remotely: apply the retention on the
# destination (bucket lifecycle rule or a job on the backup host). Keep the
# destination encrypted at rest and with restricted access: the backups
# contain customers' personal data.
set -euo pipefail

OFFSITE_TARGET="${OFFSITE_TARGET:?set OFFSITE_TARGET (e.g. <user>@<backup-host>:/srv/backups/chat-manager)}"
OFFSITE_TOOL="${OFFSITE_TOOL:-rsync}"
BACKUP_ROOT="${BACKUP_ROOT:-/var/backups/chat-manager}"

[ -d "$BACKUP_ROOT" ] || { echo "Missing $BACKUP_ROOT" >&2; exit 1; }

case "$OFFSITE_TOOL" in
    rsync)
        rsync --archive --partial --exclude='*.partial' "$BACKUP_ROOT/" "${OFFSITE_TARGET%/}/"
        ;;
    rclone)
        rclone copy --exclude '*.partial' --checksum "$BACKUP_ROOT" "$OFFSITE_TARGET"
        ;;
    *)
        echo "Unsupported OFFSITE_TOOL=$OFFSITE_TOOL (use rsync or rclone)" >&2
        exit 1
        ;;
esac

echo "Offsite copy OK: $BACKUP_ROOT -> $OFFSITE_TARGET ($OFFSITE_TOOL)"
