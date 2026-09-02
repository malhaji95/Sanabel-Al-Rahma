<?php

namespace App\Filament\Resources\ReferralResource\Pages;

use App\Filament\Resources\ReferralResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

class CreateReferral extends CreateRecord
{
    protected static string $resource = ReferralResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['code'] = strtoupper(Str::random(10));
        $data['issued_at'] = now();
        $data['status'] = 'issued';

        return $data;
    }
}
