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
export $(grep -E '^DB_(HOST|PORT|DATABASE|USERNAME|PASSWORD)=' "$ENV_FILE" | xargs -d '\n')
export PGPASSWORD="$DB_PASSWORD"

TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

scripts/backup.sh "$TMP_DIR" >/dev/null
BACKUP_FILE="$(find "$TMP_DIR" -name '*.enc' | head -1)"

scripts/restore.sh "$BACKUP_FILE" "$VERIFY_DB" >/dev/null

count() {
    psql --host="$DB_HOST" --port="$DB_PORT" --username="$DB_USERNAME" --dbname="$1" \
        -tAc "SELECT count(*) FROM $2" 2>/dev/null || echo "missing"
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

psql --host="$DB_HOST" --port="$DB_PORT" --username="$DB_USERNAME" --dbname=postgres \
    -c "DROP DATABASE IF EXISTS \"$VERIFY_DB\";" >/dev/null

if [[ "$FAILED" -ne 0 ]]; then
    echo "Restore test FAILED." >&2
    exit 1
fi

echo "Restore test passed: the backup restores into a clean database."
