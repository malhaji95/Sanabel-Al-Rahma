<?php

namespace App\Livewire;

use App\Models\Region;
use App\Models\SponsorshipRate;
use App\Services\DonorCaseList;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * T-26 — the donor browse screen. Monthly and one-time cases are two separate
 * lists, ordered by the ranking service, and every card is masked.
 */
class BrowseCases extends Component
{
    use WithPagination;

    /*
     | T-189 — «الفلاتر: جميع الحالات, دعم شهري, دعم لمرة واحدة», 29 October.
     |
     | «جميع الحالات» is the one a visitor lands on: the page is for choosing
     | a family, and starting it on one of the two kinds hides half of them
     | behind a tab nobody was told to press.
     */
    #[Url]
    public string $supportType = 'all';

    #[Url]
    public ?int $regionId = null;

    /*
     | T-98 — «زكاة مستحقة». The association asked for a door of its own on the
     | top bar, and a door needs somewhere to lead: this narrows the list to the
     | files a zakat payment may be spent on. Which of the eight categories a
     | family falls under stays behind the association's door (rule 2); the
     | donor is told that zakat is accepted here, and that is the whole of what
     | they need in order to decide.
     */
    #[Url]
    public bool $zakat = false;

    /*
     | T-208 — «وموجود فلتر للكفلات», 1 November; T-228 — «مابدنا الكفالات,
     | تظهر في الحالات / ولكن, في صفحة الكفالات لازم يظهر كل كفاله, ايش
     | نوعها يتيم ارمله طالب», the same day.
     |
     | One of the association's classifications, or null for all of them.
     | It is read only on the sponsorships page: on the list of cases the
     | row of doors is not drawn and this stays null, which is what they
     | asked for.
     */
    #[Url]
    public ?string $kind = null;

    /*
     | Whether this is the sponsorships page rather than the list of cases.
     | Protected, so it is the class that decides and not the browser:
     | `BrowseSponsorships` is the same list behind its own doors.
     */
    protected bool $sponsorships = false;

    public function updatedKind(): void
    {
        $this->resetPage();
    }

    public function updatedSupportType(): void
    {
        $this->resetPage();
    }

    public function updatedZakat(): void
    {
        $this->resetPage();
    }

    public function updatedRegionId(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $perPage = 12;
        $page = $this->getPage();

        // withCovered: the donor's list keeps a family whose month is answered,
        // at the bottom and marked as such (T-83). The page is ranked, loaded
        // and masked behind one cache (T-87).
        $cases = app(DonorCaseList::class);

        /*
         | T-228 — the doors, and only on the page they were asked for.
         |
         | On the list of cases there are none, so nothing narrows and every
         | family shows. On the sponsorships page they are the association's
         | three kinds, and the page opens on all of them at once: «لازم يظهر
         | كل كفاله». A chip narrows it to one.
         */
        $doors = $this->sponsorships ? $cases->sponsorshipDoors() : [];

        // A door nobody declared is not a question, so it is let go of
        // rather than answered with an empty grid.
        if ($this->kind !== null && ! array_key_exists($this->kind, $doors)) {
            $this->kind = null;
        }

        $list = $cases->page(
            $this->supportType,
            $this->regionId,
            $page,
            $perPage,
            withCovered: true,
            zakatOnly: $this->zakat,
            kinds: $this->kind !== null ? [$this->kind] : array_keys($doors),
        );

        return view('livewire.browse-cases', [
            // T-208 — the doors that have somebody behind them, in the
            // association's own order. Empty when the association has closed
            // the switch on the words, and then the row of chips is not drawn.
            'kinds' => $doors,
            // T-228 — which page this is: its heading, and whether the
            // hadith the association wrote for «الحالات» sits above it.
            'sponsorships' => $this->sponsorships,
            /*
             | T-210 — the price of each door, which the sponsorships page
             | reads and the list of cases does not. Null rather than an
             | empty list on the list of cases: the card draws no
             | sponsorship block at all there, and an empty list means
             | «this is the sponsorships page and no price is set yet».
             */
            'rates' => $this->sponsorships ? SponsorshipRate::published() : null,
            'cases' => collect($list['cards']),
            'total' => $list['total'],
            'hasMore' => $list['total'] > $page * $perPage,
            /*
             | T-189 — «والفلاتر التي في الأعلى فقط المناطق, لا نحتاج إظهار
             | القرى», 29 October. One list of a hundred and forty-seven
             | places, most of them villages a donor has never heard of, is
             | not a filter anybody uses. The five areas are, and choosing one
             | already covers every village under it — the ranking filters
             | down the tree.
             */
            'regions' => Region::where('is_active', true)
                ->where('type', 'area')
                ->orderBy('name_ar')
                ->pluck('name_ar', 'id'),
        ])->layout('layouts.app');
    }
}
