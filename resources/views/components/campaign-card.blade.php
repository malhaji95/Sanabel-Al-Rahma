@props(['campaign'])

<article class="card">
    <h3 class="font-bold">{{ $campaign->title_ar }}</h3>

    @if ($campaign->body_ar)
        <p class="mt-2 line-clamp-3 text-sm text-slate-600">{{ $campaign->body_ar }}</p>
    @endif

    <div class="mt-4">
        <div class="flex items-center justify-between text-sm">
            <span class="text-slate-500">{{ __('sanabel.campaign.collected') }}</span>
            <span class="font-medium">{{ $campaign->progressPercent() }}%</span>
        </div>
        <div class="mt-1 h-2 overflow-hidden rounded-full bg-slate-100">
            <div class="h-full bg-sanabel-500" style="width: {{ $campaign->progressPercent() }}%"></div>
        </div>
        <p class="mt-2 text-sm">
            {{ number_format($campaign->collected_amount) }} /
            {{ number_format($campaign->goal_amount) }} {{ $campaign->currency }}
        </p>
    </div>

    {{-- Shown to the donor before payment (docs/03-rules.md 7). --}}
    <details class="mt-4 text-sm">
        <summary class="cursor-pointer text-sanabel-700">{{ __('sanabel.campaign.surplus_policy') }}</summary>
        <p class="mt-2 text-slate-600">{{ $campaign->surplus_policy_text_ar }}</p>
    </details>

    @unless ($campaign->acceptsPledges())
        <p class="mt-3 text-sm text-slate-500">{{ __('sanabel.public.campaign_closed') }}</p>
    @endunless
</article>
