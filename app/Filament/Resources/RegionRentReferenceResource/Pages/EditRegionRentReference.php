<?php

namespace App\Filament\Resources\RegionRentReferenceResource\Pages;

use App\Filament\Resources\RegionRentReferenceResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditRegionRentReference extends EditRecord
{
    protected static string $resource = RegionRentReferenceResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\ViewAction::make()];
    }
}
