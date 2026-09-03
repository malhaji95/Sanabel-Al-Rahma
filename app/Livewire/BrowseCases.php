<?php

namespace App\Livewire;

use App\Http\Resources\MaskedCaseResource;
use App\Models\Region;
use App\Services\RankingService;
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
        $ranked = app(RankingService::class)->fundingList($this->supportType, $this->regionId);

        $perPage = 12;
        $page = $this->getPage();

        $cases = $ranked
            ->forPage($page, $perPage)
            ->map(fn (array $row) => (new MaskedCaseResource($row['beneficiary'], $row['confirmed']))->resolve())
            ->values();

        return view('livewire.browse-cases', [
            'cases' => $cases,
            'total' => $ranked->count(),
            'hasMore' => $ranked->count() > $page * $perPage,
            'regions' => Region::where('is_active', true)->orderBy('name_ar')->pluck('name_ar', 'id'),
        ])->layout('layouts.app');
    }
}
