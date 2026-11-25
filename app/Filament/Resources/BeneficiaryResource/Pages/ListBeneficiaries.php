<?php

namespace App\Filament\Resources\BeneficiaryResource\Pages;

use App\Filament\Resources\BeneficiaryResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListBeneficiaries extends ListRecords
{
    protected static string $resource = BeneficiaryResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }

    /*
     | T-326 — «يظهر خيار عرض 25 ملفًا لكل صفحة, ولكن لا تظهر إمكانية الانتقال
     | إلى الصفحات التالية», a delegate, 25 November.
     |
     | The paging itself was never broken. The delegate's region held fewer
     | than twenty-six files, so there was no second page, and Filament drew
     | no links because there was nowhere to go.
     |
     | What the screen never said is how many files there are. Filament's own
     | «عرض ١ إلى ٣ من ٣» lives behind `@container (min-width: 56rem)`, and the
     | container is the table card inside the panel, narrower than the window
     | by the width of the sidebar. On the tablet a delegate carries it is
     | always below that, so the one sentence that would have ended the
     | question is the one sentence the delegate never sees.
     |
     | Here it is read from the same query the table pages over, so a search
     | or a filter moves it, and it sits in the subheading where no breakpoint
     | reaches it.
     */
    public function getSubheading(): ?string
    {
        $shown = $this->getFilteredTableQuery()->count();
        $inScope = static::getResource()::getEloquentQuery()->count();

        if ($shown === $inScope) {
            return trans_choice('sanabel.beneficiary.count_in_scope', $shown, [
                'count' => number_format($shown),
            ]);
        }

        return trans_choice('sanabel.beneficiary.count_filtered', $shown, [
            'count' => number_format($shown),
            'total' => number_format($inScope),
        ]);
    }
}
