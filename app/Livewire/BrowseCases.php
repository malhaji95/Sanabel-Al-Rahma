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

    #[Url]
    public string $supportType = 'monthly';

    #[Url]
    public ?int $regionId = null;

    public function updatedSupportType(): void
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
            ->page($this->supportType, $this->regionId, $page, $perPage, withCovered: true);

        return view('livewire.browse-cases', [
            'cases' => collect($list['cards']),
            'total' => $list['total'],
            'hasMore' => $list['total'] > $page * $perPage,
            'regions' => Region::where('is_active', true)->orderBy('name_ar')->pluck('name_ar', 'id'),
        ])->layout('layouts.app');
    }
}
