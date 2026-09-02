<?php

namespace App\Filament\Resources\ScoringWeightResource\Pages;

use App\Filament\Resources\ScoringWeightResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditScoringWeight extends EditRecord
{
    protected static string $resource = ScoringWeightResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\ViewAction::make()];
    }
}
