<?php

namespace App\Filament\Resources\ReferralRequestResource\Pages;

use App\Filament\Resources\ReferralRequestResource;
use App\Models\Beneficiary;
use App\Services\HealthPathService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * T-145 — a household that asked over the telephone, written down by whoever
 * took the call. Through the service, so the refusal of a second open asking
 * for the same kind holds here as well as on the family's own page.
 */
class CreateReferralRequest extends CreateRecord
{
    protected static string $resource = ReferralRequestResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(HealthPathService::class)->apply(
                Beneficiary::withoutGlobalScopes()->findOrFail($data['beneficiary_id']),
                $data['provider_type'],
                $data['specialty_ar'] ?? null,
                $data['note_ar'] ?? null,
            );
        } catch (\RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            $this->halt();
        }
    }
}
