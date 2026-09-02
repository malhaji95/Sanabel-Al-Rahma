#!/usr/bin/env bash
#
# T-41 — restore a backup into a database, and verify it.
#
# Usage: BACKUP_PASSPHRASE=... scripts/restore.sh <backup-file> <target-database>
#
# Restoring into a *clean* database is the check that matters: a backup nobody
# has restored is not a backup. scripts/verify-restore.sh runs this end to end.
set -euo pipefail

BACKUP_FILE="${1:?usage: restore.sh <backup-file> <target-database>}"
TARGET_DB="${2:?usage: restore.sh <backup-file> <target-database>}"
ENV_FILE="${ENV_FILE:-.env}"

if [[ -z "${BACKUP_PASSPHRASE:-}" ]]; then
    echo "BACKUP_PASSPHRASE is required." >&2
    exit 1
fi

# shellcheck disable=SC2046
export $(grep -E '^DB_(HOST|PORT|USERNAME|PASSWORD)=' "$ENV_FILE" | xargs -d '\n')

if [[ -f "$BACKUP_FILE.sha256" ]]; then
    sha256sum --check "$BACKUP_FILE.sha256"
fi

export PGPASSWORD="$DB_PASSWORD"

psql --host="$DB_HOST" --port="$DB_PORT" --username="$DB_USERNAME" --dbname=postgres \
    -v ON_ERROR_STOP=1 -c "DROP DATABASE IF EXISTS \"$TARGET_DB\";"
psql --host="$DB_HOST" --port="$DB_PORT" --username="$DB_USERNAME" --dbname=postgres \
    -v ON_ERROR_STOP=1 -c "CREATE DATABASE \"$TARGET_DB\";"

openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -pass env:BACKUP_PASSPHRASE -in "$BACKUP_FILE" \
| gunzip \
| psql --host="$DB_HOST" --port="$DB_PORT" --username="$DB_USERNAME" --dbname="$TARGET_DB" \
    -v ON_ERROR_STOP=1 --quiet

echo "Restored $BACKUP_FILE into $TARGET_DB"
