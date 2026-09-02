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
            <x-empty-state :title="__('sanabel.public.no_cases')" />
        @else
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($cases as $case)
                    <x-masked-case-card :case="$case">
                        @auth
                            <livewire:add-to-basket
                                :file-number="$case['file_number']"
                                :remaining="$case['remaining_amount']"
                                :key="'basket-' . $case['file_number']"
                            />
                        @else
                            <a href="{{ route('login') }}" class="btn-primary w-full no-underline">
                                {{ __('sanabel.public.login_to_donate') }}
                            </a>
                        @endauth
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
