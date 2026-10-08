<?php

namespace App\Filament\Resources\DisbursementResource\Pages;

use App\Filament\Resources\DisbursementResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDisbursements extends ListRecords
{
    protected static string $resource = DisbursementResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
