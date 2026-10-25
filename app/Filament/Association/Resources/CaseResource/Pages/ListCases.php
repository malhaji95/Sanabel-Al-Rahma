<?php

namespace App\Filament\Association\Resources\CaseResource\Pages;

use App\Filament\Association\Resources\CaseResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCases extends ListRecords
{
    protected static string $resource = CaseResource::class;

    /** T-144 — the association's part starts with registering a household. */
    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
