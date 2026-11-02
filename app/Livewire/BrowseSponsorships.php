<?php

namespace App\Livewire;

use App\Services\DonorCaseList;

/**
 * T-228 — «مابدنا الكفالات, تظهر في الحالات / ولكن, في صفحة الكفالات لازم
 * يظهر كل كفاله, ايش نوعها يتيم ارمله طالب», 1 November.
 *
 * The same list of families, behind the association's own doors: the row of
 * chips T-208 put on /cases belongs here, and nowhere else. It opens on
 * every door at once, because what they asked to see is every sponsorship
 * and the kind each one is — which the card already carries (T-205, T-217).
 *
 * A subclass rather than a second screen: one component, one view, one set
 * of filters, and the difference between the two pages is which doors are
 * drawn above the grid.
 */
class BrowseSponsorships extends BrowseCases
{
    protected bool $sponsorships = true;

    /**
     * Nothing to show when the association has closed the words.
     *
     * Rule 2 — to be shown a family under «يتيم» is to be told that family
     * is an orphan file, which is the disclosure `show_sponsorship_kind`
     * governs. With the switch closed this page would publish exactly that
     * and call it a page, so it sends the visitor to the list of cases,
     * where every one of these families already is.
     */
    public function mount(): void
    {
        if (app(DonorCaseList::class)->sponsorshipDoors() === []) {
            $this->redirectRoute('cases.browse', navigate: true);
        }
    }

    /**
     * The same view as the list of cases, and the layout named here as well.
     *
     * APP-06 holds that a component reachable from `routes/web.php` names
     * its own layout, and it reads the file rather than following
     * inheritance — rightly, because a full page that inherits its layout
     * from a parent is one refactor away from rendering with none, and a
     * page without the app layout is a page without the security headers.
     */
    public function render()
    {
        return parent::render()->layout('layouts.app');
    }
}
