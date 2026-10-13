<?php

namespace App\Filament\Resources\ContentCardResource\Pages;

use App\Filament\Resources\ContentCardResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListContentCards extends ListRecords
{
    protected static string $resource = ContentCardResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
