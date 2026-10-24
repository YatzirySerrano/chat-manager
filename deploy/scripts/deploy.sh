#!/usr/bin/env bash
# Chat Manager — zero-surprise deployment with release directories.
#
# Layout (created once, see docs/infraestructura-y-despliegue.md):
#   /var/www/chat-manager/releases/<timestamp>   one directory per release
#   /var/www/chat-manager/shared/.env            production environment (chmod 640)
#   /var/www/chat-manager/shared/storage         persistent storage (attachments, logs...)
#   /var/www/chat-manager/current -> releases/<timestamp>
#
# Usage (as the deploy user):
#   REPOSITORY=<git-url-of-your-repository> deploy/scripts/deploy.sh [git-ref]   default ref: main
#
# The deploy user only needs the sudo rules of deploy/sudoers/chat-manager:
# the root-owned backup script installed in /usr/local/sbin (never a script
# inside the releases, which this user owns), the PHP-FPM reload and the
# scheduler restart.
#
# Variables: REPOSITORY (required), APP_DIR, KEEP_RELEASES, PHP_FPM_SERVICE,
# PHP, BACKUP_DATABASE_COMMAND, HEALTH_URL (default: APP_URL of shared/.env
# + /health/ready), SMOKE_ATTEMPTS, SMOKE_INTERVAL, AUTO_ROLLBACK (default 1),
# KEEP_FAILED_RELEASE (default 0).
#
# Steps: build the new release apart (composer, npm), run the read-only
# preflight, back up the database, maintenance mode, additive migrations,
# atomic switch, caches, graceful restart of workers/Reverb/scheduler and a
# mandatory smoke test of /health/ready. Any failure before the switch
# leaves the current release untouched and removes the unfinished release
# directory (or marks it FAILED when kept); a failed smoke test rolls back to
# the previous release, and rollback.sh marks the abandoned release FAILED so
# it is never chosen again. /health/ready is also probed BEFORE the switch:
# when it was already failing (stopped scheduler, critical backlog...), a
# failed smoke test is not the new code's fault and no automatic rollback is
# done; the exact rollback command is printed instead.
set -euo pipefail

# Files created by this user in the shared storage (logs, caches, the
# maintenance flag) must stay writable by the www-data group of PHP-FPM and
# the workers; storage directories carry the setgid bit (see the docs).
umask 0002

APP_DIR="${APP_DIR:-/var/www/chat-manager}"
REPOSITORY="${REPOSITORY:-}"
REF="${1:-main}"
KEEP_RELEASES="${KEEP_RELEASES:-5}"
PHP_FPM_SERVICE="${PHP_FPM_SERVICE:-php8.4-fpm}"
PHP="${PHP:-php}"
BACKUP_DATABASE_COMMAND="${BACKUP_DATABASE_COMMAND:-/usr/local/sbin/chat-manager-backup-database}"
HEALTH_URL="${HEALTH_URL:-}"
SMOKE_ATTEMPTS="${SMOKE_ATTEMPTS:-6}"
SMOKE_INTERVAL="${SMOKE_INTERVAL:-5}"
AUTO_ROLLBACK="${AUTO_ROLLBACK:-1}"
KEEP_FAILED_RELEASE="${KEEP_FAILED_RELEASE:-0}"

RELEASE="$(date -u +%Y%m%d%H%M%S)"
RELEASE_DIR="$APP_DIR/releases/$RELEASE"
SHARED_DIR="$APP_DIR/shared"
CURRENT="$APP_DIR/current"
switched=0
in_maintenance=0

log() { printf '\n[%s] %s\n' "$(date -u +%H:%M:%S)" "$*"; }
fail() { printf '\nERROR: %s\n' "$*" >&2; exit 1; }

# A release that never became current is removed, so it can never be picked
# by rollback.sh nor counted among the kept releases.
on_exit() {
    local status=$?

    if [ "$status" -ne 0 ] && [ "$switched" -eq 0 ] && [ -d "$RELEASE_DIR" ]; then
        if [ "$KEEP_FAILED_RELEASE" = "1" ]; then
            date -u '+%Y-%m-%dT%H:%M:%SZ failed before activation (kept with KEEP_FAILED_RELEASE=1)' > "$RELEASE_DIR/FAILED" || true
            printf 'The unfinished release %s was kept for inspection (KEEP_FAILED_RELEASE=1) and marked FAILED; delete it afterwards.\n' "$RELEASE_DIR" >&2
        else
            rm -rf "$RELEASE_DIR"
            printf 'The unfinished release %s was removed.\n' "$RELEASE_DIR" >&2
        fi
    fi

    if [ "$status" -ne 0 ] && [ "$in_maintenance" -eq 1 ]; then
        printf '\nThe site is still in MAINTENANCE MODE and current points to %s.\nFix the problem and deploy again, or run deploy/scripts/rollback.sh; then: cd %s && %s artisan up\n' \
            "$(readlink "$CURRENT" || echo '?')" "$CURRENT" "$PHP" >&2
    fi
}
trap on_exit EXIT

env_value() {
    grep -E "^$1=" "$SHARED_DIR/.env" | tail -n 1 | cut -d= -f2- | sed -e 's/^["'\'']//' -e 's/["'\'']$//'
}

[ -n "$REPOSITORY" ] || fail "set REPOSITORY to the git URL of the application repository (e.g. REPOSITORY=git@<git-host>:<organization>/chat-manager.git)"
[ -f "$SHARED_DIR/.env" ] || fail "missing $SHARED_DIR/.env"
[ -d "$SHARED_DIR/storage" ] || fail "missing $SHARED_DIR/storage"

if [ -z "$HEALTH_URL" ]; then
    app_url="$(env_value APP_URL || true)"
    [ -n "$app_url" ] && HEALTH_URL="${app_url%/}/health/ready"
fi
[ -n "$HEALTH_URL" ] || fail "cannot derive the smoke test URL: set APP_URL in $SHARED_DIR/.env or HEALTH_URL"

log "Cloning $REF into $RELEASE_DIR"
git clone --quiet --depth 1 --branch "$REF" "$REPOSITORY" "$RELEASE_DIR"
cd "$RELEASE_DIR"
git rev-parse HEAD > REVISION

log "Linking shared .env and storage"
rm -rf storage
ln -s "$SHARED_DIR/storage" storage
ln -s "$SHARED_DIR/.env" .env

log "Installing PHP dependencies (no dev)"
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress

log "Building frontend assets"
# Browsershot uses the system Chromium (EXPORTS_CHROME_PATH); puppeteer must
# stay in node_modules at runtime, but its bundled browser is not downloaded.
export PUPPETEER_SKIP_DOWNLOAD=true
npm ci --no-audit --no-fund
npm run build
rm -f public/hot

log "Preflight (read-only)"
"$PHP" artisan app:preflight || fail "preflight failed; the current release keeps serving traffic"

previous_release=""
baseline_healthy=1

if [ -L "$CURRENT" ]; then
    previous_release="$(basename "$(readlink -f "$CURRENT")")"

    log "Readiness baseline of the current release ($HEALTH_URL)"
    if ! curl --fail --silent --show-error --max-time 10 "$HEALTH_URL"; then
        baseline_healthy=0
        printf '\nWARNING: %s already fails before the switch (see Administración > Sistema). The deployment continues, but a failed smoke test will not trigger an automatic rollback.\n' "$HEALTH_URL" >&2
    fi

    log "Backing up the database before migrating"
    # Root-owned copy installed once (docs §16); the backup credentials and
    # /var/backups are not readable by the deploy user.
    sudo "$BACKUP_DATABASE_COMMAND"

    log "Maintenance mode on the current release"
    (cd "$CURRENT" && "$PHP" artisan down --retry=30 --refresh=15) || true
    in_maintenance=1
fi

log "Running migrations (additive only; destructive commands are blocked in production)"
"$PHP" artisan migrate --force --no-interaction

log "Adding the catalog permissions this release introduces (idempotent; existing roles are never changed)"
"$PHP" artisan app:sync-permissions --no-interaction

log "Caching configuration, routes, views and events"
"$PHP" artisan optimize
"$PHP" artisan storage:link --relative 2>/dev/null || true

log "Switching current -> $RELEASE"
ln -sfn "$RELEASE_DIR" "$APP_DIR/current.tmp"
mv -T "$APP_DIR/current.tmp" "$CURRENT"
switched=1

log "Reloading PHP-FPM (clears OPcache) and restarting background processes"
sudo systemctl reload "$PHP_FPM_SERVICE"
"$PHP" artisan queue:restart
"$PHP" artisan reverb:restart || true
sudo supervisorctl restart chatmanager-scheduler || true

"$PHP" artisan up
in_maintenance=0
# The readiness verdict is cached (operations.readiness.cache_seconds): drop it
# so the smoke test evaluates the new release instead of the baseline answer.
"$PHP" artisan cache:forget operations:readiness-verdict >/dev/null 2>&1 || true

log "Smoke test $HEALTH_URL"
healthy=0
for attempt in $(seq 1 "$SMOKE_ATTEMPTS"); do
    sleep "$SMOKE_INTERVAL"
    if curl --fail --silent --show-error --max-time 10 "$HEALTH_URL"; then
        healthy=1
        break
    fi
    printf ' (attempt %s of %s)\n' "$attempt" "$SMOKE_ATTEMPTS" >&2
done

if [ "$healthy" -ne 1 ]; then
    if [ -z "$previous_release" ]; then
        fail "readiness probe failed on the first installation: check Administración > Sistema and php artisan app:preflight (there is no previous release to return to)"
    fi

    if [ "$baseline_healthy" -ne 1 ]; then
        fail "readiness probe failed, but it was already failing before the switch: release $RELEASE stays active and no automatic rollback was done. Fix the operational problem (Administración > Sistema); if the new code is at fault, run $RELEASE_DIR/deploy/scripts/rollback.sh $previous_release"
    fi

    if [ "$AUTO_ROLLBACK" = "1" ]; then
        log "Readiness probe failed: rolling back to $previous_release"
        "$RELEASE_DIR/deploy/scripts/rollback.sh" "$previous_release" \
            || fail "automatic rollback failed: run deploy/scripts/rollback.sh $previous_release by hand"
        fail "release $RELEASE failed the readiness probe and was rolled back to $previous_release (migrations stay: they are additive)"
    fi

    fail "readiness probe failed after the switch (AUTO_ROLLBACK=0): run $RELEASE_DIR/deploy/scripts/rollback.sh $previous_release"
fi

log "Removing old releases (keeping $KEEP_RELEASES, never the current one)"
APP_DIR="$APP_DIR" KEEP_RELEASES="$KEEP_RELEASES" "$RELEASE_DIR/deploy/scripts/prune-releases.sh"

log "Deployed $(cat "$CURRENT/REVISION") as release $RELEASE"
