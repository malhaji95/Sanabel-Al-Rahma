<x-filament-panels::page>
    @if ($pendingSecret)
        <x-filament::section :heading="__('sanabel.two_factor.enrol_heading')">
            <p class="text-sm text-gray-600 dark:text-gray-400">
                {{ __('sanabel.two_factor.enrol_help') }}
            </p>

            <p class="mt-4 font-mono text-lg tracking-widest" dir="ltr">{{ $pendingSecret }}</p>

            <p class="mt-2 break-all text-xs text-gray-500" dir="ltr">{{ $this->provisioningUri() }}</p>
        </x-filament::section>
    @endif

    <form wire:submit="confirm" class="mt-6 space-y-6">
        {{ $this->form }}

        <x-filament::button type="submit">
            {{ __('sanabel.two_factor.confirm') }}
        </x-filament::button>
    </form>
</x-filament-panels::page>
