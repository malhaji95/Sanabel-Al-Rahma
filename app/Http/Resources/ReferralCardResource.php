<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * docs/04-permissions.md hard rule 4 — a provider sees only the referral
 * presented to it: file number, validity, discount type.
 */
class ReferralCardResource extends JsonResource
{
    public const ALLOWED_KEYS = ['file_number', 'valid', 'valid_until', 'discount_type', 'discount_value'];

    public function toArray(Request $request): array
    {
        return [
            'file_number' => $this->resource->beneficiary->file_number,
            'valid' => $this->resource->isUsable(),
            'valid_until' => $this->resource->expires_at?->toDateString(),
            'discount_type' => $this->resource->provider->discount_type,
            'discount_value' => $this->resource->provider->discount_value,
        ];
    }
}
