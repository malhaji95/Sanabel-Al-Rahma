<?php

namespace App\Livewire;

use App\Models\Region;
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
        $list = app(DonorCaseList::class)
            ->page($this->supportType, $this->regionId, $page, $perPage, withCovered: true, zakatOnly: $this->zakat);

        return view('livewire.browse-cases', [
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
