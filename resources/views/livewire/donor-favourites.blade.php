<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl">{{ __('sanabel.favourites.title') }}</h1>
            <p class="mt-1 text-sm" style="color: var(--text-muted);">
                {{ __('sanabel.favourites.help') }}
            </p>
        </div>

        <div class="flex flex-wrap gap-3">
            <a href="{{ route('cases.browse') }}" class="btn-secondary no-underline">{{ __('sanabel.public.cases') }}</a>
            <a href="{{ route('donor.portal') }}" class="btn-secondary no-underline">{{ __('sanabel.public.account_home') }}</a>
        </div>
    </div>

    @if ($cases->isEmpty())
        <x-empty-state :title="__('sanabel.favourites.empty')" :body="__('sanabel.favourites.empty_help')">
            <a href="{{ route('cases.browse') }}" class="btn-primary mt-2 no-underline">
                {{ __('sanabel.public.browse_cases') }}
            </a>
        </x-empty-state>
    @else
        <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($cases as $case)
                <x-masked-case-card :case="$case">
                    <div class="space-y-2">
                        <a href="{{ route('opportunity', $case['file_number']) }}"
                           class="btn-primary block text-center no-underline">
                            {{ __('sanabel.public.open_family_file') }}
                        </a>

                        <livewire:favourite-toggle
                            :file-number="$case['file_number']"
                            :key="'fav-list-' . $case['file_number']"
                        />
                    </div>
                </x-masked-case-card>
            @endforeach
        </div>
    @endif

    {{--
        T-180 — a family the donor kept whose file is not published today: it
        was withdrawn, closed, or its month is being reworked. The row stays on
        the list, so the family comes back if the file is published again, and
        saying so is better than a list that quietly grows shorter.
    --}}
    @if ($restingCount > 0)
        <p class="text-sm" style="color: var(--text-muted);">
            {{ __('sanabel.favourites.resting', ['count' => $restingCount]) }}
        </p>
    @endif
</div>
