{{--
    T-113 — the staged-funding example the association put in their package:
    «جرّب الفرق بين هدف المرحلة والاحتياج الكامل».

    Two differences from their demonstration, both deliberate.

    The share it opens on is the association's own `funding_target_percent`,
    not a number typed into a page. The example teaches the thing the system
    actually does, and it teaches today's setting.

    And it computes its figures in PHP before any script runs, so a reader
    with no JavaScript still sees a worked example rather than empty boxes.
    Alpine only recomputes them as the sliders move.
--}}
@props(['section'])

@php
    use App\Models\Setting;

    // A round number, labelled as an example, in the association's currency.
    $need = 100000;
    $percent = (int) Setting::value('funding_target_percent', config('sanabel.setting_defaults.funding_target_percent'));
    $percent = max(10, min(100, $percent));
    $collected = 50;

    $goal = intdiv($need * $percent, 100);
    $paid = intdiv($goal * $collected, 100);
@endphp

<section class="card mt-6">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h3 class="text-base">{{ $section->title_ar ?: __('sanabel.page.funding_example') }}</h3>
        <span class="badge bg-gold-100 text-gold-800 dark:bg-gold-900/50 dark:text-gold-100">
            {{ __('sanabel.page.example_figures') }}
        </span>
    </div>

    @if ($section->body_ar)
        <p class="mt-2 whitespace-pre-line text-sm leading-loose" style="color: var(--text-muted);">
            {{ $section->body_ar }}
        </p>
    @endif

    <div
        class="mt-5"
        x-data="{
            need: {{ $need }},
            percent: {{ $percent }},
            collected: {{ $collected }},
            get goal() { return Math.floor(this.need * this.percent / 100) },
            get paid() { return Math.floor(this.goal * this.collected / 100) },
            get ofNeed() { return this.need ? Math.round(this.paid / this.need * 1000) / 10 : 0 },
            money(value) { return new Intl.NumberFormat('en-US').format(value) },
        }"
    >
        <p class="tabular text-2xl font-bold">{{ number_format($need) }}
            <span class="text-sm font-normal" style="color: var(--text-muted);">
                {{ \App\Services\Money::label() }} — {{ __('sanabel.funding.real_need') }}
            </span>
        </p>

        <div class="mt-4 grid gap-5 sm:grid-cols-2">
            <div>
                <label class="field-label" for="fx-percent">
                    {{ __('sanabel.funding.target_amount') }}:
                    <span class="tabular" x-text="percent + '%'">{{ $percent }}%</span>
                </label>
                <input id="fx-percent" type="range" min="10" max="100" step="10"
                       value="{{ $percent }}" x-model.number="percent" class="w-full">
                <p class="tabular mt-1 text-sm">
                    <span x-text="money(goal)">{{ number_format($goal) }}</span>
                    <span style="color: var(--text-muted);">{{ \App\Services\Money::label() }}</span>
                </p>
            </div>

            <div>
                <label class="field-label" for="fx-collected">
                    {{ __('sanabel.page.collected_of_target') }}:
                    <span class="tabular" x-text="collected + '%'">{{ $collected }}%</span>
                </label>
                <input id="fx-collected" type="range" min="0" max="100" step="5"
                       value="{{ $collected }}" x-model.number="collected" class="w-full">
                <p class="tabular mt-1 text-sm">
                    <span x-text="money(paid)">{{ number_format($paid) }}</span>
                    <span style="color: var(--text-muted);">{{ \App\Services\Money::label() }}</span>
                </p>
            </div>
        </div>

        <div class="mt-5 grid gap-3 sm:grid-cols-2">
            <div class="rounded-xl p-3" style="background-color: var(--page);">
                <p class="text-xs" style="color: var(--text-muted);">{{ __('sanabel.funding.target_remaining') }}</p>
                <p class="tabular text-xl font-bold" x-text="collected + '%'">{{ $collected }}%</p>
                <div class="meter mt-1.5">
                    <div class="meter-fill" :style="`width: ${collected}%`" style="width: {{ $collected }}%"></div>
                </div>
            </div>

            <div class="rounded-xl p-3" style="background-color: var(--page);">
                <p class="text-xs" style="color: var(--text-muted);">{{ __('sanabel.funding.real_need') }}</p>
                <p class="tabular text-xl font-bold" x-text="ofNeed + '%'">{{ round($paid / $need * 100, 1) }}%</p>
                <div class="meter mt-1.5">
                    <div class="meter-fill" :style="`width: ${ofNeed}%`" style="width: {{ $paid / $need * 100 }}%"></div>
                </div>
            </div>
        </div>

        <p class="mt-4 text-sm">
            <span style="color: var(--text-muted);">{{ __('sanabel.public.remaining') }}:</span>
            <span class="tabular font-bold" x-text="money(need - paid)">{{ number_format($need - $paid) }}</span>
            <span style="color: var(--text-muted);">{{ \App\Services\Money::label() }}</span>
        </p>
    </div>
</section>
