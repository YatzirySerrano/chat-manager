#!/usr/bin/env bash
# Chat Manager — return to the previous release (code only).
#
# Usage: deploy/scripts/rollback.sh [release-timestamp]
#   Without argument: the newest COMPLETE release older than the current one
#   (releases are compared by name, a UTC timestamp, never by modification
#   time, so a newer release that failed before being activated is never
#   chosen). A release is complete when it has REVISION,
#   vendor/autoload.php and public/build/manifest.json, and it is never
#   chosen (nor accepted as argument) when it carries a FAILED marker.
#   ROLLBACK_DRY_RUN=1 only prints the chosen release and changes nothing.
#
# The release being left is marked FAILED (a file with the date) once the
# switch is done, so a release rolled back by hand or by the smoke test of
# deploy.sh is never activated again by a later rollback. To reuse it on
# purpose, delete releases/<name>/FAILED after checking it.
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
RELEASES="$APP_DIR/releases"

is_complete() {
    [ -f "$RELEASES/$1/REVISION" ] && [ -f "$RELEASES/$1/vendor/autoload.php" ] && [ -f "$RELEASES/$1/public/build/manifest.json" ]
}

is_failed() {
    [ -e "$RELEASES/$1/FAILED" ]
}

current_release="$(basename "$(readlink -f "$CURRENT")")"
target=""

if [ -n "${1:-}" ]; then
    [[ "$1" =~ ^[0-9]{14}$ ]] || { echo "Invalid release name: $1 (expected a timestamp such as 20261021120000)." >&2; exit 1; }
    [ -d "$RELEASES/$1" ] || { echo "Release $1 does not exist." >&2; exit 1; }
    is_complete "$1" || { echo "Release $1 is incomplete (missing REVISION, vendor or the frontend build); refusing to activate it." >&2; exit 1; }
    ! is_failed "$1" || { echo "Release $1 is marked FAILED ($(head -n 1 "$RELEASES/$1/FAILED")); delete $RELEASES/$1/FAILED first if you are sure it is good." >&2; exit 1; }
    target="$1"
else
    for candidate in $(find "$RELEASES" -mindepth 1 -maxdepth 1 -type d -name '[0-9]*' -exec basename {} \; | grep -E '^[0-9]{14}$' | sort -r); do
        if [[ "$candidate" < "$current_release" ]] && is_complete "$candidate" && ! is_failed "$candidate"; then
            target="$candidate"
            break
        fi
    done
fi

[ -n "$target" ] || { echo "No complete, non-failed release older than $current_release to roll back to." >&2; exit 1; }
[ "$target" != "$current_release" ] || { echo "Release $target is already active." >&2; exit 1; }

echo "Rolling back $current_release -> $target"

if [ "${ROLLBACK_DRY_RUN:-0}" = "1" ]; then
    echo "Dry run: nothing changed."
    exit 0
fi

cd "$RELEASES/$target"

"$PHP" artisan down --retry=30 || true
"$PHP" artisan optimize
ln -sfn "$RELEASES/$target" "$APP_DIR/current.tmp"
mv -T "$APP_DIR/current.tmp" "$CURRENT"
date -u "+%Y-%m-%dT%H:%M:%SZ rolled back to $target" > "$RELEASES/$current_release/FAILED" \
    || echo "WARNING: could not mark $current_release as FAILED." >&2

sudo systemctl reload "$PHP_FPM_SERVICE"
"$PHP" artisan queue:restart
"$PHP" artisan reverb:restart || true
sudo supervisorctl restart chatmanager-scheduler || true
"$PHP" artisan up

echo "Active release: $target ($(cat "$CURRENT/REVISION"))"
