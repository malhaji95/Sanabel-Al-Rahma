<?php

namespace App\Filament\Resources\DonationResource\Pages;

use App\Filament\Resources\DonationResource;
use Filament\Resources\Pages\EditRecord;

class EditDonation extends EditRecord
{
    protected static string $resource = DonationResource::class;

    /** A verified donation never reaches this page — the Policy denies it. */
    protected function authorizeAccess(): void
    {
        abort_unless(auth()->user()->can('update', $this->record), 403);
    }
}
