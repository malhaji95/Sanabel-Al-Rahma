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
    // T-103 — never below zero: a campaign that overshoots its goal has
    // nothing remaining, not a negative amount outstanding.
    $outstanding = max(0, $campaign->goal_amount - $collected);
@endphp

<article id="campaign-{{ $campaign->id }}" class="card-interactive flex h-full flex-col scroll-mt-24">
    <h3 class="text-base">{{ $campaign->title_ar }}</h3>

    @if ($campaign->body_ar)
        <p class="mt-2 line-clamp-3 text-sm leading-relaxed" style="color: var(--text-muted);">
            {{ $campaign->body_ar }}
        </p>
    @endif

    {{--
        T-103 — the project, fenced off from the family's monthly funding
        below it. The association read a card on 15 October that said a
        project stood at 40% and that the month was fully covered, and read
        the two as a contradiction. They are two different asks on one page,
        so each now says which one it is.
    --}}
    <div class="mt-5 rounded-xl border p-3" style="border-color: var(--border);">
        <p class="mb-2 text-xs font-semibold" style="color: var(--text-muted);">
            {{ __('sanabel.public.project_block') }}
        </p>

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

        {{--
            T-103 — three named figures instead of «24,000 / SYP 60,000».
            A slash between two numbers has no direction in a page that runs
            right to left, and the association said plainly that a donor
            cannot tell which of the two is which.
        --}}
        <dl class="mt-3 grid grid-cols-3 gap-2 text-center text-sm">
            @foreach ([
                [__('sanabel.public.goal'), $campaign->goal_amount, 'var(--text-muted)'],
                [__('sanabel.public.collected'), $collected, 'var(--accent)'],
                [__('sanabel.public.remaining'), $outstanding, 'var(--text)'],
            ] as [$label, $figure, $colour])
                <div class="rounded-lg p-2" style="background-color: var(--page);">
                    <dt class="text-xs" style="color: var(--text-muted);">{{ $label }}</dt>
                    <dd class="tabular mt-0.5 font-bold" style="color: {{ $colour }};">{{ number_format($figure) }}</dd>
                </div>
            @endforeach
        </dl>

        <p class="mt-1.5 text-center text-xs" style="color: var(--text-muted);">{{ $campaign->currency }}</p>
    </div>

    @if ($monthly)
        <div class="mt-3 rounded-xl border p-3 text-sm" style="border-color: var(--border); background-color: var(--page);">
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

            {{--
                T-103 — the link says where the money goes, which was the
                association's second note of 15 October. This block reports
                one month, deliberately: the turn of the month belongs to the
                family's own page. So when the month is answered the link
                says that is where the next one is funded, rather than
                reading «تبرع الآن» under the words «اكتملت التغطية».
            --}}
            @if ($monthly['is_covered'])
                <p class="mt-1 text-xs" style="color: var(--text-muted);">
                    {{ __('sanabel.public.next_month_on_file') }}
                </p>
            @endif

            <a href="{{ route('opportunity', $monthly['file_number']) }}"
               class="mt-2 inline-block text-xs underline" style="color: var(--accent);">
                @if ($monthly['is_covered'])
                    {{ __('sanabel.public.open_family_file') }}
                @else
                    {{ __('sanabel.public.donate_for_month', ['month' => $monthly['coverage_month_label']]) }}
                @endif
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
