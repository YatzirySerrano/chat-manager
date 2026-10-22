#!/usr/bin/env bash
# Chat Manager — return to the previous release (code only).
#
# Usage: deploy/scripts/rollback.sh [release-timestamp]
#   Without argument: the release before the current one.
#
# The database is NOT rolled back: migrations are additive, so the previous
# code keeps working with the newer schema. If a release must be undone at
# the data level, restore the backup taken by deploy.sh (see
# docs/recuperacion-y-respaldos.md); never run migrate:rollback in production.
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/chat-manager}"
PHP_FPM_SERVICE="${PHP_FPM_SERVICE:-php8.4-fpm}"
PHP="${PHP:-php}"
CURRENT="$APP_DIR/current"

current_release="$(basename "$(readlink -f "$CURRENT")")"

if [ -n "${1:-}" ]; then
    target="$1"
else
    target="$(ls -1t "$APP_DIR/releases" | grep -v -x "$current_release" | head -n 1 || true)"
fi

[ -n "$target" ] && [ -d "$APP_DIR/releases/$target" ] || { echo "No release to roll back to." >&2; exit 1; }

echo "Rolling back $current_release -> $target"
cd "$APP_DIR/releases/$target"

"$PHP" artisan down --retry=30 || true
"$PHP" artisan optimize
ln -sfn "$APP_DIR/releases/$target" "$APP_DIR/current.tmp"
mv -T "$APP_DIR/current.tmp" "$CURRENT"

sudo systemctl reload "$PHP_FPM_SERVICE"
"$PHP" artisan queue:restart
"$PHP" artisan reverb:restart || true
sudo supervisorctl restart chatmanager-scheduler || true
"$PHP" artisan up

echo "Active release: $target ($(cat "$CURRENT/REVISION" 2>/dev/null || echo 'sin REVISION'))"
