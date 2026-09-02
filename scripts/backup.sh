#!/usr/bin/env bash
#
# T-41 — encrypted database backup.
#
# Usage: BACKUP_PASSPHRASE=... scripts/backup.sh [output-dir]
# Reads DB_* from .env. Writes <db>-<timestamp>.sql.gz.enc plus a SHA-256 sum.
set -euo pipefail

OUT_DIR="${1:-storage/backups}"
ENV_FILE="${ENV_FILE:-.env}"

if [[ -z "${BACKUP_PASSPHRASE:-}" ]]; then
    echo "BACKUP_PASSPHRASE is required; a backup is never written unencrypted." >&2
    exit 1
fi

# shellcheck disable=SC2046
export $(grep -E '^DB_(CONNECTION|HOST|PORT|DATABASE|USERNAME|PASSWORD)=' "$ENV_FILE" | xargs -d '\n')

mkdir -p "$OUT_DIR"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
TARGET="$OUT_DIR/${DB_DATABASE}-${STAMP}.sql.gz.enc"

PGPASSWORD="$DB_PASSWORD" pg_dump \
    --host="$DB_HOST" --port="$DB_PORT" \
    --username="$DB_USERNAME" --dbname="$DB_DATABASE" \
    --no-owner --no-privileges \
| gzip -9 \
| openssl enc -aes-256-cbc -pbkdf2 -iter 200000 -salt -pass env:BACKUP_PASSPHRASE \
> "$TARGET"

sha256sum "$TARGET" > "$TARGET.sha256"

echo "Backup written: $TARGET"
echo "Checksum:       $TARGET.sha256"
