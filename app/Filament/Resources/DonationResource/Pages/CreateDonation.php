<?php

namespace App\Filament\Resources\DonationResource\Pages;

use App\Exceptions\DuplicateTransactionRef;
use App\Filament\Resources\DonationResource;
use App\Payments\PaymentGateway;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateDonation extends CreateRecord
{
    protected static string $resource = DonationResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(PaymentGateway::class)->record($data);
        } catch (DuplicateTransactionRef $e) {
            Notification::make()->title($e->getMessage())->danger()->persistent()->send();

            $this->halt();
        }
    }
}
