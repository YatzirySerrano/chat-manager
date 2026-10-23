#!/usr/bin/env bash
# Chat Manager — remove old release directories, keeping the newest ones.
#
# Usage (as the deploy user; deploy.sh calls it after a healthy deployment):
#   deploy/scripts/prune-releases.sh
#
# Variables: APP_DIR (default /var/www/chat-manager), KEEP_RELEASES (default 5,
# the active release included). Releases are ordered by name (a UTC
# timestamp), never by modification time, and the active release is never
# removed. Having nothing to remove (first installation) is not an error.
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/chat-manager}"
KEEP_RELEASES="${KEEP_RELEASES:-5}"
RELEASES="$APP_DIR/releases"

[[ "$KEEP_RELEASES" =~ ^[1-9][0-9]*$ ]] || { echo "KEEP_RELEASES must be a positive integer." >&2; exit 1; }
[ -L "$APP_DIR/current" ] || { echo "$APP_DIR/current is not a symbolic link; nothing removed." >&2; exit 1; }

active="$(basename "$(readlink -f "$APP_DIR/current")")"

# Every release except the active one, newest first; grep -v exits with 1 when
# the active release is the only one, which must not stop the script.
others="$(find "$RELEASES" -mindepth 1 -maxdepth 1 -type d -name '[0-9]*' -exec basename {} \; \
    | grep -E '^[0-9]{14}$' | sort -r | { grep -v -x "$active" || true; })"

[ -n "$others" ] || exit 0

printf '%s\n' "$others" | tail -n +"$KEEP_RELEASES" | while read -r old; do
    rm -rf "${RELEASES:?}/$old"
    echo "Removed release $old"
done
