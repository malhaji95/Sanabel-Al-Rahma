#!/usr/bin/env bash
#
# T-41 — restore a database backup, and verify it.
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
export $(grep -E '^DB_(CONNECTION|HOST|PORT|USERNAME|PASSWORD)=' "$ENV_FILE" | xargs -d '\n')

# Creating and dropping a database needs more rights than the application user
# usually has — on a managed host it typically has none. Set RESTORE_DB_USERNAME
# and RESTORE_DB_PASSWORD to an administrative account for the restore test.
DB_USERNAME="${RESTORE_DB_USERNAME:-$DB_USERNAME}"
DB_PASSWORD="${RESTORE_DB_PASSWORD:-$DB_PASSWORD}"

if [[ -f "$BACKUP_FILE.sha256" ]]; then
    sha256sum --check "$BACKUP_FILE.sha256"
fi

decrypt() {
    openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -pass env:BACKUP_PASSPHRASE -in "$BACKUP_FILE"
}

case "${DB_CONNECTION:-mysql}" in
    mysql|mariadb)
        export MYSQL_PWD="$DB_PASSWORD"
        MYSQL=(mysql --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USERNAME")

        "${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$TARGET_DB\`;"
        "${MYSQL[@]}" -e "CREATE DATABASE \`$TARGET_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

        decrypt | gunzip | "${MYSQL[@]}" --default-character-set=utf8mb4 "$TARGET_DB"
        ;;
    pgsql|postgres|postgresql)
        export PGPASSWORD="$DB_PASSWORD"
        PSQL=(psql --host="$DB_HOST" --port="$DB_PORT" --username="$DB_USERNAME")

        "${PSQL[@]}" --dbname=postgres -v ON_ERROR_STOP=1 -c "DROP DATABASE IF EXISTS \"$TARGET_DB\";"
        "${PSQL[@]}" --dbname=postgres -v ON_ERROR_STOP=1 -c "CREATE DATABASE \"$TARGET_DB\";"

        decrypt | gunzip | "${PSQL[@]}" --dbname="$TARGET_DB" -v ON_ERROR_STOP=1 --quiet
        ;;
    *)
        echo "Unsupported DB_CONNECTION '${DB_CONNECTION}'." >&2
        exit 1
        ;;
esac

echo "Restored $BACKUP_FILE into $TARGET_DB"
