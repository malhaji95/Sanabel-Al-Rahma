<?php

namespace App\Filament\Resources\ReferralResource\Pages;

use App\Filament\Resources\ReferralResource;
use App\Models\Beneficiary;
use App\Models\Provider;
use App\Services\ReferralService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateReferral extends CreateRecord
{
    protected static string $resource = ReferralResource::class;

    /**
     * Through the service, not around it. This page used to mint the code and
     * the dates itself, which meant a card issued from the panel drew nothing
     * down from the provider's quota — the one place it matters most.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $provider = Provider::findOrFail($data['provider_id']);

        $referral = app(ReferralService::class)->issue(
            Beneficiary::withoutGlobalScopes()->findOrFail($data['beneficiary_id']),
            $provider,
        );

        // The association chose a warning rather than a block, so the card is
        // already issued by the time this is read.
        if ($provider->refresh()->quotaExceeded()) {
            Notification::make()
                ->title(__('sanabel.provider.quota_exceeded'))
                ->warning()
                ->persistent()
                ->send();
        }

        return $referral;
    }
}
