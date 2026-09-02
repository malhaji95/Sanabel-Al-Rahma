#!/usr/bin/env bash
#
# T-41 — encrypted backup of the database and the media it references.
#
# Usage: BACKUP_PASSPHRASE=... scripts/backup.sh [output-dir]
#
# Reads DB_* from .env and works against MySQL/MariaDB or PostgreSQL. Writes
# <db>-<timestamp>.sql.gz.enc plus a SHA-256 sum, and — when media is stored on
# the local disk rather than in a bucket — a matching media archive.
set -euo pipefail

OUT_DIR="${1:-storage/backups}"
ENV_FILE="${ENV_FILE:-.env}"
APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if [[ -z "${BACKUP_PASSPHRASE:-}" ]]; then
    echo "BACKUP_PASSPHRASE is required; a backup is never written unencrypted." >&2
    exit 1
fi

# shellcheck disable=SC2046
export $(grep -E '^(DB_(CONNECTION|HOST|PORT|DATABASE|USERNAME|PASSWORD)|SANABEL_MEDIA_DISK)=' "$ENV_FILE" | xargs -d '\n')

mkdir -p "$OUT_DIR"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
TARGET="$OUT_DIR/${DB_DATABASE}-${STAMP}.sql.gz.enc"

encrypt() {
    openssl enc -aes-256-cbc -pbkdf2 -iter 200000 -salt -pass env:BACKUP_PASSPHRASE
}

case "${DB_CONNECTION:-mysql}" in
    mysql|mariadb)
        # --single-transaction keeps the dump consistent without locking writers.
        MYSQL_PWD="$DB_PASSWORD" mysqldump \
            --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USERNAME" \
            --single-transaction --quick --routines --events \
            --default-character-set=utf8mb4 \
            --no-tablespaces \
            "$DB_DATABASE" \
        | gzip -9 | encrypt > "$TARGET"
        ;;
    pgsql|postgres|postgresql)
        PGPASSWORD="$DB_PASSWORD" pg_dump \
            --host="$DB_HOST" --port="$DB_PORT" \
            --username="$DB_USERNAME" --dbname="$DB_DATABASE" \
            --no-owner --no-privileges \
        | gzip -9 | encrypt > "$TARGET"
        ;;
    *)
        echo "Unsupported DB_CONNECTION '${DB_CONNECTION}'." >&2
        exit 1
        ;;
esac

sha256sum "$TARGET" > "$TARGET.sha256"
echo "Database: $TARGET"

# Media on the local disk is part of the record — a receipt or a delivery proof
# is what closes a case — so it is backed up alongside the database.
MEDIA_DIR="$APP_DIR/storage/app/private/media"

if [[ "${SANABEL_MEDIA_DISK:-media_local}" == "media_local" && -d "$MEDIA_DIR" ]]; then
    MEDIA_TARGET="$OUT_DIR/${DB_DATABASE}-media-${STAMP}.tar.gz.enc"

    tar -C "$APP_DIR/storage/app/private" -cf - media \
    | gzip -9 | encrypt > "$MEDIA_TARGET"

    sha256sum "$MEDIA_TARGET" > "$MEDIA_TARGET.sha256"
    echo "Media:    $MEDIA_TARGET"
else
    echo "Media:    skipped (stored in a bucket, back that up on its own schedule)"
fi
