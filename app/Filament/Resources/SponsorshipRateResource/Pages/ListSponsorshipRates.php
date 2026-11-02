<?php

namespace App\Filament\Resources\SponsorshipRateResource\Pages;

use App\Filament\Resources\SponsorshipRateResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSponsorshipRates extends ListRecords
{
    protected static string $resource = SponsorshipRateResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
