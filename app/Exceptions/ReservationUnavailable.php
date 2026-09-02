<?php

namespace App\Exceptions;

use RuntimeException;

/** A family cannot be reserved beyond its remaining need (docs/03-rules.md §6). */
class ReservationUnavailable extends RuntimeException
{
    public static function exceedsRemaining(string $fileNumber): self
    {
        return new self(__('sanabel.basket.exceeds_remaining').' ('.$fileNumber.')');
    }
}
