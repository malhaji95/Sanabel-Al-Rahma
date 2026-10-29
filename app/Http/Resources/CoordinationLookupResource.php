<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * docs/04-permissions.md hard rule 3 — an out-of-scope lookup by national ID
 * returns a fixed, tiny set of values. Nothing else ever leaves this class:
 * no name, no file number, no contact, nothing that reaches the family.
 */
class CoordinationLookupResource extends JsonResource
{
    public const ALLOWED_KEYS = [
        // T-182 — «مقيمة» joined the fixed set on 29 October. It is a yes or a
        // no about the asking account's own area and carries no place with it,
        // so the rule above is unchanged: still a handful of flags, still
        // nothing that reaches the family.
        'registered', 'resident', 'assessment_state', 'has_active_assessment', 'supported_this_period', 'coverage',
    ];

    public function toArray(Request $request): array
    {
        return [
            'registered' => (bool) $this->resource['registered'],
            'resident' => (bool) ($this->resource['resident'] ?? false),
            // none | active | expired. Never assessed and assessed-but-expired
            // are different answers for the association asking.
            'assessment_state' => $this->resource['assessment_state'],
            'has_active_assessment' => (bool) $this->resource['has_active_assessment'],
            'supported_this_period' => (bool) $this->resource['supported_this_period'],
            'coverage' => $this->resource['coverage'], // none|partial|full
        ];
    }
}
