<?php

namespace App\Exceptions;

use RuntimeException;

/** Rule 1 — a duplicate transaction_ref is rejected with a message asking for review. */
class DuplicateTransactionRef extends RuntimeException
{
    public static function make(string $ref): self
    {
        return new self(__('sanabel.donations.duplicate_ref').' ('.$ref.')');
    }
}
