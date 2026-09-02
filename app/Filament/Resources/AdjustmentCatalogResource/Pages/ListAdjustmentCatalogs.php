<?php

namespace App\Filament\Resources\AdjustmentCatalogResource\Pages;

use App\Filament\Resources\AdjustmentCatalogResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAdjustmentCatalogs extends ListRecords
{
    protected static string $resource = AdjustmentCatalogResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
