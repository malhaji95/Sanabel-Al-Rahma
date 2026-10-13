{{--
    T-83 — `monthly` is the family's own monthly funding, resolved by the
    controller through MaskedCaseResource and nothing else, and null when the
    campaign belongs to no family or when the association has closed the
    switch. Rule 2 admits no second route to a donor's screen, and a campaign
    page is a donor's screen.
--}}
@props(['campaign', 'monthly' => null])

@php
    $collected = $campaign->collectedAmount();
    $progress = $campaign->progressPercent($collected);
@endphp

<article id="campaign-{{ $campaign->id }}" class="card-interactive flex h-full flex-col scroll-mt-24">
    <h3 class="text-base">{{ $campaign->title_ar }}</h3>

    @if ($campaign->body_ar)
        <p class="mt-2 line-clamp-3 text-sm leading-relaxed" style="color: var(--text-muted);">
            {{ $campaign->body_ar }}
        </p>
    @endif

    <div class="mt-5">
        <div class="flex items-baseline justify-between text-sm">
            <span style="color: var(--text-muted);">{{ __('sanabel.public.progress') }}</span>
            <span class="tabular font-semibold">{{ $progress }}%</span>
        </div>

        <div
            class="meter mt-1.5"
            role="progressbar"
            aria-valuenow="{{ $progress }}"
            aria-valuemin="0"
            aria-valuemax="100"
            aria-label="{{ __('sanabel.public.progress') }}"
        >
            @if ($progress > 0)
                <div @class(['meter-fill', 'meter-fill-complete' => $progress >= 100]) style="width: {{ $progress }}%"></div>
            @endif
        </div>

        <p class="tabular mt-2.5 text-sm">
            <span class="font-bold">{{ number_format($collected) }}</span>
            <span style="color: var(--text-muted);">/ {{ number_format($campaign->goal_amount) }} {{ $campaign->currency }}</span>
        </p>
    </div>

    @if ($monthly)
        <div class="mt-4 rounded-xl p-3 text-sm" style="background-color: var(--page);">
            <p class="font-medium">{{ __('sanabel.funding.show_monthly_funding_on_campaign') }}</p>

            @unless ($monthly['is_covered'])
                <p class="mt-1 text-xs" style="color: var(--text-muted);">
                    {{ __('sanabel.funding.covers_month', ['month' => $monthly['coverage_month_label']]) }}
                </p>
            @endunless

            <p class="mt-1.5">
                @if ($monthly['is_covered'])
                    <span class="font-bold">
                        {{ __('sanabel.funding.month_complete', ['month' => $monthly['coverage_month_label']]) }}
                    </span>
                @else
                    <span style="color: var(--text-muted);">{{ __('sanabel.public.remaining') }}:</span>
                    <span class="tabular font-bold">{{ number_format($monthly['remaining_amount']) }}</span>
                    <span style="color: var(--text-muted);">
                        {{ __('sanabel.public.of_total', ['total' => number_format($monthly['need_amount'])]) }}
                        {{ $monthly['currency'] }}
                    </span>
                @endif
            </p>

            <a href="{{ route('opportunity', $monthly['file_number']) }}"
               class="mt-2 inline-block text-xs underline" style="color: var(--accent);">
                {{ __('sanabel.public.donate_now') }}
            </a>
        </div>
    @endif

    {{-- Shown to the donor before payment (docs/03-rules.md §7). --}}
    <details class="mt-4 text-sm">
        <summary class="cursor-pointer font-medium" style="color: var(--accent);">
            {{ __('sanabel.campaign.surplus_policy') }}
        </summary>
        <p class="mt-2 leading-relaxed" style="color: var(--text-muted);">
            {{ $campaign->surplus_policy_text_ar }}
        </p>
    </details>

    @if ($campaign->acceptsPledges($collected) && auth()->user()?->donor)
        <livewire:pledge-to-campaign :campaign="$campaign" :key="'pledge-' . $campaign->id" />
    @elseif (! $campaign->acceptsPledges($collected))
        <p class="mt-auto pt-4 text-sm" style="color: var(--text-muted);">
            {{ __('sanabel.public.campaign_closed') }}
        </p>
    @endif

    <div class="mt-3">
        <x-share-button
            :url="route('campaigns.public')"
            :title="$campaign->title_ar" />
    </div>
</article>
