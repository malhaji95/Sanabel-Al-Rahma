<?php

namespace App\Filament\Resources\RegionRateResource\Pages;

use App\Filament\Resources\RegionRateResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListRegionRates extends ListRecords
{
    protected static string $resource = RegionRateResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
