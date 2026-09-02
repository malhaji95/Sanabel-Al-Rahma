@props(['campaign'])

@php $progress = $campaign->progressPercent(); @endphp

<article class="card-interactive flex h-full flex-col">
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
            <span class="font-bold">{{ number_format($campaign->collected_amount) }}</span>
            <span style="color: var(--text-muted);">/ {{ number_format($campaign->goal_amount) }} {{ $campaign->currency }}</span>
        </p>
    </div>

    {{-- Shown to the donor before payment (docs/03-rules.md §7). --}}
    <details class="mt-4 text-sm">
        <summary class="cursor-pointer font-medium" style="color: var(--accent);">
            {{ __('sanabel.campaign.surplus_policy') }}
        </summary>
        <p class="mt-2 leading-relaxed" style="color: var(--text-muted);">
            {{ $campaign->surplus_policy_text_ar }}
        </p>
    </details>

    @unless ($campaign->acceptsPledges())
        <p class="mt-auto pt-4 text-sm" style="color: var(--text-muted);">
            {{ __('sanabel.public.campaign_closed') }}
        </p>
    @endunless
</article>
