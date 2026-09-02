<div>
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl">{{ __('sanabel.public.cases') }}</h1>
            <p class="mt-1 text-sm text-slate-500">
                {{ __('sanabel.public.cases_count', ['count' => $total]) }}
            </p>
        </div>

        <div class="flex flex-wrap gap-3">
            {{-- Two separate lists, as the donor UI requires. --}}
            <div class="inline-flex overflow-hidden rounded-lg border border-slate-300">
                @foreach (['monthly', 'one_time'] as $type)
                    <button
                        type="button"
                        wire:click="$set('supportType', '{{ $type }}')"
                        @class([
                            'px-4 py-2 text-sm',
                            'bg-sanabel-600 text-white' => $supportType === $type,
                            'bg-white text-slate-700 hover:bg-slate-50' => $supportType !== $type,
                        ])
                    >{{ __('sanabel.masked.need_type.' . $type) }}</button>
                @endforeach
            </div>

            <select wire:model.live="regionId" class="field w-48">
                <option value="">{{ __('sanabel.public.all_regions') }}</option>
                @foreach ($regions as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </div>
    </div>

    @if ($cases->isEmpty())
        <div class="card text-center text-slate-500">
            {{ __('sanabel.public.no_cases') }}
        </div>
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
                        <a href="{{ route('login') }}" class="btn-primary mt-4 w-full">
                            {{ __('sanabel.public.login_to_donate') }}
                        </a>
                    @endauth
                </x-masked-case-card>
            @endforeach
        </div>

        @if ($hasMore)
            <div class="mt-6 text-center">
                <button type="button" wire:click="nextPage" class="btn-secondary">
                    {{ __('sanabel.public.load_more') }}
                </button>
            </div>
        @endif
    @endif
</div>
