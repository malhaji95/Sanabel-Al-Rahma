<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /**
     * The wallet is hidden on the model, so `attributesToArray()` drops it and
     * the form would open empty on an association that has one — and save the
     * blank back. Put it back by name.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['wallet_encrypted'] = $this->record->wallet_encrypted;

        return $data;
    }
}
