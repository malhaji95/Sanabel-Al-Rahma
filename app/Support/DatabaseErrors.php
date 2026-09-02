<?php

namespace App\Support;

use Illuminate\Database\QueryException;

/**
 * Reading a database error without tying the application to one engine.
 *
 * The unique constraints are what actually hold several of the rules in
 * docs/03-rules.md — a duplicate `transaction_ref`, a second file for the same
 * national ID, a repeated visit sync. The application only has to turn the
 * violation into the right message, and it should not care whether PostgreSQL
 * or MySQL raised it.
 */
class DatabaseErrors
{
    /**
     * SQLSTATE codes for a unique/primary key violation.
     *
     *   23505 — PostgreSQL `unique_violation`
     *   23000 — MySQL and MariaDB `integrity constraint violation`
     *           (driver code 1062 for a duplicate entry)
     */
    private const UNIQUE_VIOLATION = ['23505', '23000'];

    public static function isUniqueViolation(QueryException $e, ?string $column = null): bool
    {
        if (! in_array($e->errorInfo[0] ?? null, self::UNIQUE_VIOLATION, true)) {
            return false;
        }

        if ($column === null) {
            return true;
        }

        // Both engines name the offending index or column in the message, but
        // not in the same shape, so match on the column name alone.
        return str_contains(strtolower($e->getMessage()), strtolower($column));
    }
}
