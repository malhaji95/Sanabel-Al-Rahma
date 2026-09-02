<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * docs/04-permissions.md hard rule 3 — an out-of-scope lookup by national ID
 * returns four values only. Nothing else ever leaves this class.
 */
class CoordinationLookupResource extends JsonResource
{
    public const ALLOWED_KEYS = [
        'registered', 'has_active_assessment', 'supported_this_period', 'coverage',
    ];

    public function toArray(Request $request): array
    {
        return [
            'registered' => (bool) $this->resource['registered'],
            'has_active_assessment' => (bool) $this->resource['has_active_assessment'],
            'supported_this_period' => (bool) $this->resource['supported_this_period'],
            'coverage' => $this->resource['coverage'], // none|partial|full
        ];
    }
}
