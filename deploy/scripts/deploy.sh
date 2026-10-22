#!/usr/bin/env bash
# Chat Manager — zero-surprise deployment with release directories.
#
# Layout (created once, see docs/infraestructura-y-despliegue.md):
#   /var/www/chat-manager/releases/<timestamp>   one directory per release
#   /var/www/chat-manager/shared/.env            production environment (chmod 640)
#   /var/www/chat-manager/shared/storage         persistent storage (attachments, logs...)
#   /var/www/chat-manager/current -> releases/<timestamp>
#
# Usage (as the deploy user, with sudo rights for the service reloads and
# deploy/scripts/backup-database.sh):
#   deploy/scripts/deploy.sh [git-ref]        default ref: main
#
# Steps: build the new release apart (composer, npm), run the read-only
# preflight, back up the database, maintenance mode, additive migrations,
# atomic switch, caches, graceful restart of workers/Reverb/scheduler, smoke
# test. Any failure before the switch leaves the current release untouched.
set -euo pipefail

# Files created by this user in the shared storage (logs, caches, the
# maintenance flag) must stay writable by the www-data group of PHP-FPM and
# the workers; storage directories carry the setgid bit (see the docs).
umask 0002

APP_DIR="${APP_DIR:-/var/www/chat-manager}"
REPOSITORY="${REPOSITORY:-git@github.com:example/chat-manager.git}"
REF="${1:-main}"
KEEP_RELEASES="${KEEP_RELEASES:-5}"
PHP_FPM_SERVICE="${PHP_FPM_SERVICE:-php8.4-fpm}"
HEALTH_URL="${HEALTH_URL:-}"
PHP="${PHP:-php}"

RELEASE="$(date -u +%Y%m%d%H%M%S)"
RELEASE_DIR="$APP_DIR/releases/$RELEASE"
SHARED_DIR="$APP_DIR/shared"
CURRENT="$APP_DIR/current"

log() { printf '\n[%s] %s\n' "$(date -u +%H:%M:%S)" "$*"; }
fail() { printf '\nERROR: %s\n' "$*" >&2; exit 1; }

[ -f "$SHARED_DIR/.env" ] || fail "missing $SHARED_DIR/.env"
[ -d "$SHARED_DIR/storage" ] || fail "missing $SHARED_DIR/storage"

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

if [ -L "$CURRENT" ]; then
    log "Backing up the database before migrating"
    # Root only: the backup credentials (/etc/chat-manager/backup.cnf) and
    # /var/backups are not readable by the deploy user.
    sudo "$RELEASE_DIR/deploy/scripts/backup-database.sh"

    log "Maintenance mode on the current release"
    (cd "$CURRENT" && "$PHP" artisan down --retry=30 --refresh=15) || true
    trap 'printf "\nERROR: deployment of release %s stopped while the site is in maintenance mode.\nCheck where current points (readlink %s), fix the problem and deploy again or run deploy/scripts/rollback.sh; then php artisan up\n" "$RELEASE" "$CURRENT" >&2' ERR
fi

log "Running migrations (additive only; destructive commands are blocked in production)"
"$PHP" artisan migrate --force --no-interaction

log "Caching configuration, routes, views and events"
"$PHP" artisan optimize
"$PHP" artisan storage:link --relative 2>/dev/null || true

log "Switching current -> $RELEASE"
ln -sfn "$RELEASE_DIR" "$APP_DIR/current.tmp"
mv -T "$APP_DIR/current.tmp" "$CURRENT"

log "Reloading PHP-FPM (clears OPcache) and restarting background processes"
sudo systemctl reload "$PHP_FPM_SERVICE"
"$PHP" artisan queue:restart
"$PHP" artisan reverb:restart || true
sudo supervisorctl restart chatmanager-scheduler || true

"$PHP" artisan up
trap - ERR

if [ -n "$HEALTH_URL" ]; then
    log "Smoke test $HEALTH_URL"
    sleep 5
    curl --fail --silent --show-error --max-time 10 "$HEALTH_URL" || fail "readiness probe failed after the switch: run deploy/scripts/rollback.sh"
fi

log "Removing old releases (keeping $KEEP_RELEASES)"
ls -1dt "$APP_DIR"/releases/*/ | tail -n +"$((KEEP_RELEASES + 1))" | xargs -r rm -rf

log "Deployed $(cat "$CURRENT/REVISION") as release $RELEASE"
