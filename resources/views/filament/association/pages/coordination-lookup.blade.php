<x-filament-panels::page>
    <form wire:submit="lookup" class="space-y-6">
        {{ $this->form }}

        <x-filament::button type="submit">
            {{ __('sanabel.actions.lookup') }}
        </x-filament::button>
    </form>

    @if ($result !== null)
        {{-- Four values. Nothing that identifies the family is shown here. --}}
        <x-filament::section :heading="__('sanabel.actions.lookup')" class="mt-6">
            <dl class="grid gap-4 sm:grid-cols-2">
                @foreach ([
                    'registered' => __('sanabel.coordination.registered'),
                    'has_active_assessment' => __('sanabel.coordination.has_active_assessment'),
                    'supported_this_period' => __('sanabel.coordination.supported_this_period'),
                ] as $key => $label)
                    <div>
                        <dt class="text-sm text-gray-500">{{ $label }}</dt>
                        <dd class="font-medium">
                            {{ $result[$key] ? __('sanabel.yes') : __('sanabel.no') }}
                        </dd>
                    </div>
                @endforeach

                <div>
                    <dt class="text-sm text-gray-500">{{ __('sanabel.coordination.coverage') }}</dt>
                    <dd class="font-medium">
                        {{ __('sanabel.coordination.coverage_' . $result['coverage']) }}
                    </dd>
                </div>
            </dl>
        </x-filament::section>
    @endif
</x-filament-panels::page>
