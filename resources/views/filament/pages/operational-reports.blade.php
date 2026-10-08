<x-filament-panels::page>
    {{ $this->form }}

    @php($report = $this->getReport())

    @php($sections = [
        'money' => ['heading' => __('sanabel.reports.money'), 'money' => true, 'keys' => [
            'collected', 'earmarked', 'general', 'allocated', 'disbursed', 'pending', 'reserved', 'reversed',
        ]],
        'families' => ['heading' => __('sanabel.reports.families'), 'money' => false, 'keys' => [
            'published', 'complete', 'critical', 'middle', 'low', 'awaiting_approval', 'needs_reassessment',
        ]],
        'sponsorships' => ['heading' => __('sanabel.reports.sponsorships'), 'money' => false, 'keys' => [
            'active', 'due', 'overdue', 'lapsed',
        ]],
        'failures' => ['heading' => __('sanabel.reports.failures'), 'money' => false, 'keys' => [
            'disbursements', 'disputed', 'distribution_items', 'rejected_donations',
        ]],
        'campaigns' => ['heading' => __('sanabel.reports.campaigns'), 'money' => false, 'keys' => [
            'active', 'funded', 'lapsed',
        ]],
    ])

    @foreach ($sections as $group => $section)
        <x-filament::section :heading="$section['heading']">
            <dl class="grid gap-4 sm:grid-cols-3 lg:grid-cols-4">
                @foreach ($section['keys'] as $key)
                    <div class="rounded-xl border p-3" style="border-color: var(--gray-200);">
                        <dt class="text-xs" style="color: var(--gray-500);">
                            {{ __('sanabel.reports.keys.' . $group . '.' . $key) }}
                        </dt>
                        <dd class="mt-1 text-xl font-bold tabular-nums">
                            {{ number_format($report[$group][$key] ?? 0) }}
                            @if ($section['money'])
                                <span class="text-xs font-normal" style="color: var(--gray-500);">
                                    {{ config('sanabel.currency') }}
                                </span>
                            @endif
                        </dd>
                    </div>
                @endforeach

                @if ($group === 'campaigns')
                    <div class="rounded-xl border p-3" style="border-color: var(--gray-200);">
                        <dt class="text-xs" style="color: var(--gray-500);">
                            {{ __('sanabel.reports.keys.campaigns.goal') }}
                        </dt>
                        <dd class="mt-1 text-xl font-bold tabular-nums">
                            {{ number_format($report['campaigns']['goal']) }}
                            <span class="text-xs font-normal" style="color: var(--gray-500);">
                                {{ config('sanabel.currency') }}
                            </span>
                        </dd>
                    </div>
                @endif
            </dl>
        </x-filament::section>
    @endforeach

    <p class="text-xs" style="color: var(--gray-500);">{{ __('sanabel.reports.note') }}</p>
</x-filament-panels::page>
