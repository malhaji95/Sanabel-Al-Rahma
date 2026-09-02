<?php

namespace App\Filament\Resources\SponsorshipResource\Pages;

use App\Filament\Resources\SponsorshipResource;
use App\Services\SponsorshipService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateSponsorship extends CreateRecord
{
    protected static string $resource = SponsorshipResource::class;

    /** The service generates one installment per month in the range. */
    protected function handleRecordCreation(array $data): Model
    {
        return app(SponsorshipService::class)->create($data);
    }
}
