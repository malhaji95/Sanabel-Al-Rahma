#!/usr/bin/env bash
#
# T-41 — the restore test. Backs up the live database, restores it into a
# throwaway one, and compares row counts on the tables that carry the record.
#
# Run this on a schedule. A backup that has never been restored is not a backup.
set -euo pipefail

ENV_FILE="${ENV_FILE:-.env}"
VERIFY_DB="${VERIFY_DB:-sanabel_restore_check}"
export BACKUP_PASSPHRASE="${BACKUP_PASSPHRASE:?BACKUP_PASSPHRASE is required}"

# shellcheck disable=SC2046
export $(grep -E '^DB_(CONNECTION|HOST|PORT|DATABASE|USERNAME|PASSWORD)=' "$ENV_FILE" | xargs -d '\n')

# Creating and dropping the throwaway database needs more rights than the
# application user usually has. Set RESTORE_DB_USERNAME / RESTORE_DB_PASSWORD
# to an administrative account; without them this falls back to the app user.
ADMIN_USERNAME="${RESTORE_DB_USERNAME:-$DB_USERNAME}"
ADMIN_PASSWORD="${RESTORE_DB_PASSWORD:-$DB_PASSWORD}"

TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

scripts/backup.sh "$TMP_DIR" >/dev/null
BACKUP_FILE="$(find "$TMP_DIR" -name '*.sql.gz.enc' | head -1)"

scripts/restore.sh "$BACKUP_FILE" "$VERIFY_DB" >/dev/null

count() {
    case "${DB_CONNECTION:-mysql}" in
        mysql|mariadb)
            MYSQL_PWD="$ADMIN_PASSWORD" mysql --host="$DB_HOST" --port="$DB_PORT" \
                --user="$ADMIN_USERNAME" --skip-column-names --batch \
                -e "SELECT count(*) FROM \`$2\`" "$1" 2>/dev/null || echo "missing"
            ;;
        *)
            PGPASSWORD="$ADMIN_PASSWORD" psql --host="$DB_HOST" --port="$DB_PORT" \
                --username="$ADMIN_USERNAME" --dbname="$1" \
                -tAc "SELECT count(*) FROM $2" 2>/dev/null || echo "missing"
            ;;
    esac
}

drop_verify_db() {
    case "${DB_CONNECTION:-mysql}" in
        mysql|mariadb)
            MYSQL_PWD="$ADMIN_PASSWORD" mysql --host="$DB_HOST" --port="$DB_PORT" \
                --user="$ADMIN_USERNAME" -e "DROP DATABASE IF EXISTS \`$VERIFY_DB\`;" >/dev/null
            ;;
        *)
            PGPASSWORD="$ADMIN_PASSWORD" psql --host="$DB_HOST" --port="$DB_PORT" \
                --username="$ADMIN_USERNAME" --dbname=postgres \
                -c "DROP DATABASE IF EXISTS \"$VERIFY_DB\";" >/dev/null
            ;;
    esac
}

FAILED=0

for TABLE in beneficiaries assessments donations donation_allocations audit_log regions users; do
    SOURCE="$(count "$DB_DATABASE" "$TABLE")"
    RESTORED="$(count "$VERIFY_DB" "$TABLE")"

    if [[ "$SOURCE" == "$RESTORED" ]]; then
        printf '  ok   %-24s %s rows\n' "$TABLE" "$SOURCE"
    else
        printf '  FAIL %-24s source=%s restored=%s\n' "$TABLE" "$SOURCE" "$RESTORED"
        FAILED=1
    fi
done

drop_verify_db

if [[ "$FAILED" -ne 0 ]]; then
    echo "Restore test FAILED." >&2
    exit 1
fi

echo "Restore test passed: the backup restores into a clean database."
