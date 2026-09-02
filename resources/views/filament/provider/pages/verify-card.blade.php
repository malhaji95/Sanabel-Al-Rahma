<x-filament-panels::page>
    <form wire:submit="verify" class="space-y-6">
        {{ $this->form }}

        <x-filament::button type="submit">
            {{ __('sanabel.referral.verify') }}
        </x-filament::button>
    </form>

    @if ($card !== null)
        {{-- Three fields. The provider never learns who the family is. --}}
        <x-filament::section :heading="__('sanabel.referral.card')" class="mt-6">
            <dl class="grid gap-4 sm:grid-cols-2">
                <div>
                    <dt class="text-sm text-gray-500">{{ __('sanabel.beneficiary.file_number') }}</dt>
                    <dd class="font-medium">{{ $card['file_number'] }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-gray-500">{{ __('sanabel.referral.valid') }}</dt>
                    <dd class="font-medium">
                        {{ $card['valid'] ? __('sanabel.yes') : __('sanabel.no') }}
                        ({{ $card['valid_until'] }})
                    </dd>
                </div>
                <div>
                    <dt class="text-sm text-gray-500">{{ __('sanabel.provider.discount_type') }}</dt>
                    <dd class="font-medium">
                        {{ __('sanabel.discount_type.' . $card['discount_type']) }} — {{ $card['discount_value'] }}
                    </dd>
                </div>
            </dl>

            @if ($card['valid'])
                <x-slot name="footerActions">
                    <x-filament::button wire:click="redeem" color="success">
                        {{ __('sanabel.actions.redeem') }}
                    </x-filament::button>
                </x-slot>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
