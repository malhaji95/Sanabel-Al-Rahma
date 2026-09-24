<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The association's decision sheet: a money value it has not approved stays
 * empty, and zero is never assumed for it. So an assessment that would need
 * such a value is refused rather than computed on a number nobody approved.
 */
class ReferenceValueMissing extends RuntimeException
{
    /** @param  array<int,string>  $missing  human-readable labels */
    public function __construct(public readonly array $missing, public readonly string $regionName)
    {
        parent::__construct(__('sanabel.reference.missing_values', [
            'region' => $regionName,
            'values' => implode('، ', $missing),
        ]));
    }
}
