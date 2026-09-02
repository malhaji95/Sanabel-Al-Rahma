<?php

namespace App\Filament\Resources\RegionRentReferenceResource\Pages;

use App\Filament\Resources\RegionRentReferenceResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListRegionRentReferences extends ListRecords
{
    protected static string $resource = RegionRentReferenceResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
