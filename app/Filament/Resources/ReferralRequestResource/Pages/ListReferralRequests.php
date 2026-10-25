<?php

namespace App\Filament\Resources\ReferralRequestResource\Pages;

use App\Filament\Resources\ReferralRequestResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListReferralRequests extends ListRecords
{
    protected static string $resource = ReferralRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }

    /** What this screen is for, said once at the top of it. */
    public function getSubheading(): ?string
    {
        return __('sanabel.health_path.subheading');
    }
}
