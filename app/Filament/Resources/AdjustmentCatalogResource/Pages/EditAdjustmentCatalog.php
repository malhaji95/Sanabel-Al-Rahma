<?php

namespace App\Filament\Resources\AdjustmentCatalogResource\Pages;

use App\Filament\Resources\AdjustmentCatalogResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAdjustmentCatalog extends EditRecord
{
    protected static string $resource = AdjustmentCatalogResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\ViewAction::make()];
    }
}
