<?php

namespace App\Filament\Resources\JobProfileResource\Pages;

use App\Filament\Resources\JobProfileResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListJobProfiles extends ListRecords
{
    protected static string $resource = JobProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
