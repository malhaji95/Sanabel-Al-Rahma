<?php

namespace App\Filament\Resources\DonationResource\Pages;

use App\Filament\Resources\DonationResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDonations extends ListRecords
{
    protected static string $resource = DonationResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }

    /**
     * T-124 — «لقيت حالة قيد المراجعة, كيف اقدر اخليها مؤكد؟», 26 October.
     *
     * The account asking already held the key: the button is on the row and
     * it was simply not obvious that the row was where to look. A screen
     * whose first state is «قيد المراجعة» should say what turns it into the
     * second, to the account that can do it and to the one that cannot.
     */
    public function getSubheading(): ?string
    {
        return auth()->user()?->can_('verify_payment')
            ? __('sanabel.donations.verify_help')
            : __('sanabel.donations.verify_help_readonly');
    }
}
