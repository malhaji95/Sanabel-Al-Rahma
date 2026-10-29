<?php

namespace App\Livewire;

use App\Models\Setting;
use App\Services\PlatformStats;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * T-183 — «نعم نريده أن يتحرك أمام العين بدون تحميل», 29 October.
 *
 * The strip of figures on the front page. It asked its questions once, when
 * the page was served, and a visitor reading the page while a transfer was
 * verified saw yesterday's number until they pressed reload.
 *
 * It polls rather than pushes: a broadcast would need a socket server, a
 * queue worker and a package, for a number that may move twice an hour
 * (CLAUDE.md §3). `wire:poll.visible` stops asking the moment the strip
 * scrolls out of sight, and `PlatformStats` caches the answer for less time
 * than the poll, so the database answers once for every visitor rather than
 * once for each.
 *
 * The money figures still count verified transfers only — «المبالغ لا تظهر
 * إلا بعد تأكيد أمين الصندوق» — which is where `PlatformStats` keeps it.
 */
class PlatformCounters extends Component
{
    /** Each figure has its own switch on the settings screen (T-99). */
    private function switched(string $key): bool
    {
        return (bool) Setting::value($key, config('sanabel.setting_defaults.'.$key));
    }

    public function render(): View
    {
        $stats = app(PlatformStats::class)->all();

        $figures = collect([
            ['stat_families', $stats['families'], __('sanabel.public.stat_families'), 'family', 'var(--accent)', false],
            ['stat_bands', $stats['bands']['critical'], __('sanabel.masked.priority.critical'), 'heart', '#b91c1c', false],
            ['stat_bands', $stats['bands']['middle'], __('sanabel.masked.priority.middle'), 'hand-heart', 'var(--brand-gold)', false],
            ['stat_bands', $stats['bands']['low'], __('sanabel.masked.priority.low'), 'info', 'var(--text-muted)', false],
            ['stat_covered', $stats['bands']['complete'], __('sanabel.public.stat_covered'), 'check-circle', 'var(--accent)', false],
            ['stat_donated', $stats['donated'], __('sanabel.public.stat_donated'), 'card', 'var(--brand-gold)', true],
            ['stat_donations', $stats['donations'], __('sanabel.public.stat_donations'), 'hand-heart', 'var(--accent)', false],
        ])->filter(fn (array $figure) => $this->switched($figure[0]));

        return view('livewire.platform-counters', ['figures' => $figures]);
    }
}
