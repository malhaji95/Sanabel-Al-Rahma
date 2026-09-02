#!/usr/bin/env bash
#
# Deploy a new release in place.
#
#   scripts/deploy.sh [git-ref]
#
# Safe to re-run. Takes a backup before migrating, puts the app in maintenance
# mode only for the migration, and leaves the caches warm.
#
# Assets: this does NOT build them. Cloudways servers do not reliably have
# Node, so `public/build` is produced by CI or locally and uploaded. The script
# refuses to finish if the build is missing, rather than serving an unstyled app.
set -euo pipefail

REF="${1:-}"
APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$APP_DIR"

PHP="${PHP_BINARY:-php}"

say() { printf '\n\033[1m==> %s\033[0m\n' "$1"; }

say "Fetching"
git fetch --all --prune
if [[ -n "$REF" ]]; then
    git checkout --quiet "$REF"
fi
git pull --ff-only

say "Backing up before migrating"
if [[ -n "${BACKUP_PASSPHRASE:-}" ]]; then
    scripts/backup.sh "${BACKUP_DIR:-storage/backups}"
else
    echo "  BACKUP_PASSPHRASE not set — skipping. Set it: a migration without a"
    echo "  backup has no way back."
fi

say "Installing dependencies"
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

if [[ ! -f public/build/manifest.json ]]; then
    echo "public/build/manifest.json is missing." >&2
    echo "Build the assets and upload public/build before deploying:" >&2
    echo "    npm ci && npm run build" >&2
    exit 1
fi

say "Migrating"
$PHP artisan down --render="errors::503" --retry=15 || true
trap '$PHP artisan up || true' EXIT

$PHP artisan migrate --force

say "Rebuilding caches"
$PHP artisan optimize:clear
$PHP artisan config:cache
$PHP artisan route:cache
$PHP artisan view:cache
$PHP artisan event:cache

say "Restarting workers"
# Supervisor restarts the worker once it exits; this tells it to exit cleanly
# after the current job so it picks up the new code.
$PHP artisan queue:restart

$PHP artisan up
trap - EXIT

say "Done"
$PHP artisan about --only=environment
