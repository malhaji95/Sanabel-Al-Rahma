#!/bin/sh
#
# Prepares the container, then hands off to supervisor.
set -e

say() { printf '\033[1m==> %s\033[0m\n' "$1"; }

# ---- APP_KEY -----------------------------------------------------------------
#
# Not generated here on purpose. The key encrypts national IDs, phones and
# wallets, and it also keys the HMAC behind national_id_hash — the unique index
# that stops a second file being opened for the same person. A key that changes
# between deploys would make existing rows unreadable and silently break
# duplicate detection, so a missing key is a hard stop.
if [ -z "${APP_KEY:-}" ]; then
    echo "APP_KEY is not set." >&2
    echo "Generate one and set it as an environment variable — it must stay the same" >&2
    echo "for the life of the deployment:" >&2
    echo "    php artisan key:generate --show" >&2
    exit 1
fi

# ---- port --------------------------------------------------------------------
# Render supplies PORT; default matches its convention for local runs.
PORT="${PORT:-10000}"
sed "s/__PORT__/${PORT}/" /etc/nginx/nginx.conf.template > /etc/nginx/nginx.conf
say "listening on ${PORT}"

# ---- writable paths ----------------------------------------------------------
mkdir -p \
    /app/storage/framework/cache/data \
    /app/storage/framework/sessions \
    /app/storage/framework/views \
    /app/storage/logs \
    /app/storage/app/private/media \
    /app/bootstrap/cache

chown -R www-data:www-data /app/storage /app/bootstrap/cache

# ---- package manifest --------------------------------------------------------
# Rebuilt here rather than shipped, so it reflects the production dependency
# set instead of whatever was installed when the image was assembled.
say "discovering packages"
php /app/artisan package:discover --ansi

# ---- database ----------------------------------------------------------------
say "waiting for the database"
tries=0
until php /app/artisan db:show --quiet >/dev/null 2>&1; do
    tries=$((tries + 1))
    if [ "$tries" -ge 30 ]; then
        echo "Database is not reachable. Check DB_* environment variables." >&2
        php /app/artisan db:show || true
        exit 1
    fi
    sleep 2
done

say "migrating"
php /app/artisan migrate --force

# Reference data, roles, permissions, funds and settings. Idempotent.
say "seeding reference data"
php /app/artisan db:seed --force

# Synthetic families, so the demo has something to show. Rule 11: generated
# data only, never real families. Runs once; skipped if a file already exists.
if [ "${DEMO_SEED:-false}" = "true" ]; then
    if [ "$(php /app/artisan tinker --execute='echo App\Models\Beneficiary::count();' 2>/dev/null | tr -dc '0-9')" = "0" ]; then
        say "seeding synthetic demo families"
        php /app/artisan db:seed --class=SyntheticDataSeeder --force
    else
        say "demo families already present, skipping"
    fi
fi

# ---- caches ------------------------------------------------------------------
# Built here rather than in the image, so they capture the runtime environment.
say "caching configuration"
php /app/artisan config:cache
php /app/artisan route:cache
php /app/artisan view:cache

say "ready"
exec /usr/bin/supervisord -c /etc/supervisord.conf
