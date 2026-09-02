<?php

namespace App\Filament\Resources\JobProfileResource\Pages;

use App\Filament\Resources\JobProfileResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditJobProfile extends EditRecord
{
    protected static string $resource = JobProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\ViewAction::make()];
    }
}
