<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * T-284 — the household as the engine reads it is missing something the
 * engine will not guess.
 *
 * The same principle as a missing reference value: a figure nobody supplied
 * is named and the computation is refused, never estimated. A need computed
 * on a household the file does not fully describe is a wrong number that
 * looks right, and a family is paid on it.
 *
 * @param  array<int,string>  $missing  human-readable labels
 */
class HouseholdIncomplete extends RuntimeException
{
    public function __construct(public readonly array $missing)
    {
        parent::__construct(__('sanabel.reference.household_incomplete', [
            'values' => implode('، ', $missing),
        ]));
    }
}
