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
     *
     * T-307 — «أكّدنا الطلب, تم. طيب الإجراء اللي بعده؟», 16 November, and
     * the same treasurer asking the sequel to their own question. T-124
     * answered how a transfer becomes verified and stopped there, so the
     * screen ends where the work does not: the money is in the fund and
     * nothing on it says where the next step lives.
     *
     * It is one sentence rather than a new screen, because nothing is
     * missing from the system — the whole chain from a payment request to
     * the household's confirmation was built in T-143, T-215 and T-234.
     * What was missing is a line telling the account that verified the
     * money that it is done here, and where to go.
     */
    public function getSubheading(): ?string
    {
        $how = auth()->user()?->can_('verify_payment')
            ? __('sanabel.donations.verify_help')
            : __('sanabel.donations.verify_help_readonly');

        return $how.' '.__('sanabel.donations.after_verify_help');
    }
}
