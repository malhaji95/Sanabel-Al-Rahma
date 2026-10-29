<div>
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl">{{ __('sanabel.public.cases') }}</h1>
            <p class="mt-1 text-sm" style="color: var(--text-muted);">
                {{ __('sanabel.public.cases_count', ['count' => $total]) }}
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            {{-- Monthly and one-time are two separate lists, as the donor UI requires. --}}
            <div
                class="inline-flex overflow-hidden rounded-xl border p-1"
                style="border-color: var(--border); background-color: var(--card);"
                role="tablist"
                aria-label="{{ __('sanabel.beneficiary.support_type') }}"
            >
                @foreach (['monthly', 'one_time'] as $type)
                    <button
                        type="button"
                        role="tab"
                        aria-selected="{{ $supportType === $type ? 'true' : 'false' }}"
                        wire:click="$set('supportType', '{{ $type }}')"
                        class="rounded-lg px-4 py-1.5 text-sm font-medium transition"
                        @style([
                            'background-color: var(--accent); color: var(--accent-contrast)' => $supportType === $type,
                            'color: var(--text-muted)' => $supportType !== $type,
                        ])
                    >{{ __('sanabel.masked.need_type.' . $type) }}</button>
                @endforeach
            </div>

            {{-- T-98 — «زكاة مستحقة»: the files a zakat payment may be spent on.
                 A switch rather than a third tab, because it narrows either
                 list rather than replacing them. --}}
            <button
                type="button"
                wire:click="$toggle('zakat')"
                aria-pressed="{{ $zakat ? 'true' : 'false' }}"
                class="rounded-xl border px-4 py-2 text-sm font-medium transition"
                @style([
                    'border-color: var(--border); background-color: var(--card); color: var(--text-muted)' => ! $zakat,
                    'border-color: var(--accent); background-color: var(--accent); color: var(--accent-contrast)' => $zakat,
                ])
            >{{ __('sanabel.public.zakat_only') }}</button>

            <label class="sr-only" for="region-filter">{{ __('sanabel.beneficiary.region') }}</label>
            <select id="region-filter" wire:model.live="regionId" class="field w-48">
                <option value="">{{ __('sanabel.public.all_regions') }}</option>
                @foreach ($regions as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div wire:loading.class="opacity-50" class="transition-opacity">
        @if ($cases->isEmpty())
            <x-empty-state :title="$zakat ? __('sanabel.public.zakat_only_empty') : __('sanabel.public.no_cases')" />
        @else
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($cases as $case)
                    <x-masked-case-card :case="$case">
                        @auth
                            <livewire:add-to-basket
                                :file-number="$case['file_number']"
                                :remaining="$case['remaining_amount']"
                                :target-remaining="$case['target_remaining'] ?? 0"
                                :coverage-month="$case['coverage_month'] ?? null"
                                :key="'basket-' . $case['file_number'] . '-' . ($case['coverage_month'] ?? '')"
                            />

                            {{-- T-180 — keep this family, to come back to. Under
                                 the giving box, not over it: the card is an
                                 invitation to give first. --}}
                            <div class="mt-2">
                                <livewire:favourite-toggle
                                    :file-number="$case['file_number']"
                                    :key="'fav-' . $case['file_number']"
                                />
                            </div>
                        @else
                            {{--
                                T-168 — «تبرعات الملفات بالالوان ونسبة الانجاز
                                تعامل نفس معاملة الحملات, وحتى زر التبرع الان
                                نفس موضوع», 31 October.

                                The campaign cards were answered in T-123: the
                                word «تبرع» belongs to every visitor, and the
                                account is what a pledge needs, not what the
                                invitation needs. A family's card still read
                                «سجل الدخول للتبرع» — a door with its own
                                condition written on it. It reads the same as a
                                campaign's now: the invitation first, and the
                                account underneath it as the way to put your own
                                name on the gift.
                            --}}
                            <div class="space-y-2">
                                <a href="{{ route('donate.general') }}"
                                   class="btn-primary block w-full text-center no-underline">
                                    {{ __('sanabel.public.donate_now') }}
                                </a>

                                <p class="text-center text-xs" style="color: var(--text-muted);">
                                    <a href="{{ route('login') }}" style="color: var(--accent);">
                                        {{ __('sanabel.public.login') }}
                                    </a>
                                    {{ __('sanabel.public.earmark_needs_account') }}
                                </p>
                            </div>
                        @endauth

                        {{-- The opportunity's own address, and a way to send it
                             on. T-102 — it used to be labelled «تبرع الآن», the
                             same words as the button directly above it, so the
                             family's own page read as a second donate button and
                             nobody opened it. --}}
                        <div class="mt-2 flex items-center gap-2">
                            <a href="{{ route('opportunity', $case['file_number']) }}"
                               class="btn-secondary flex-1 justify-center gap-1 no-underline">
                                {{ __('sanabel.public.more_info') }}
                                <x-brand.icon name="chevron-left" class="h-4 w-4" />
                            </a>

                            @if ($case['can_share'] ?? true)
                                <x-share-button
                                    :url="route('opportunity', $case['file_number'])"
                                    :title="__('sanabel.share.case_title', ['need' => $case['need_type_label'], 'area' => $case['area_ar']])" />
                            @endif
                        </div>
                    </x-masked-case-card>
                @endforeach
            </div>

            @if ($hasMore)
                <div class="mt-8 text-center">
                    <button type="button" wire:click="nextPage" class="btn-secondary">
                        {{ __('sanabel.public.load_more') }}
                    </button>
                </div>
            @endif
        @endif
    </div>
</div>
