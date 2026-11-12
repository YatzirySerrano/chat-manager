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

# The scheduler (schedule:work) keeps the release it started with, so it must
# be restarted by whichever process manager runs it (use only one; see
# deploy/supervisor, deploy/systemd and deploy/cron). SCHEDULER_MANAGER:
# auto (default: the systemd unit when it is active, else Supervisor), systemd,
# supervisor or cron (schedule:run starts from `current` every minute; nothing
# to restart). Afterwards the withoutOverlapping mutexes are cleared: a task
# killed during the stop (stop timeout) would otherwise stay "running" until
# its mutex expires (24 h). Trade-off: a task of the old release that is still
# running (orphaned child) could overlap once with its next run.
restart_scheduler() {
    local manager="${SCHEDULER_MANAGER:-auto}"

    if [ "$manager" = "auto" ]; then
        if command -v systemctl >/dev/null 2>&1 && systemctl is-active --quiet chat-manager-scheduler.service; then
            manager="systemd"
        elif command -v supervisorctl >/dev/null 2>&1; then
            manager="supervisor"
        else
            manager="cron"
        fi
    fi

    case "$manager" in
        systemd) sudo systemctl restart chat-manager-scheduler || printf 'WARNING: could not restart chat-manager-scheduler (systemd); restart it by hand.\n' >&2 ;;
        supervisor) sudo supervisorctl restart chatmanager-scheduler || printf 'WARNING: could not restart chatmanager-scheduler (Supervisor); restart it by hand if Supervisor runs the scheduler.\n' >&2 ;;
        cron) ;;
        *) printf 'WARNING: unknown SCHEDULER_MANAGER=%s; the scheduler was not restarted.\n' "$manager" >&2 ;;
    esac

    "$PHP" artisan schedule:clear-cache || true
}

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
restart_scheduler
"$PHP" artisan up

echo "Active release: $target ($(cat "$CURRENT/REVISION"))"
