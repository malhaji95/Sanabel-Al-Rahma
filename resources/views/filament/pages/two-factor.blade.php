<x-filament-panels::page>
    @if ($pendingSecret)
        <x-filament::section :heading="__('sanabel.two_factor.enrol_heading')">
            <p class="text-sm text-gray-600 dark:text-gray-400">
                {{ __('sanabel.two_factor.enrol_help') }}
            </p>

            {{--
                T-119 — the code itself. The markup is this application's own
                output, module by module, with nothing of the URI written into
                it as text, which is why it is rendered rather than escaped.

                White behind it on both schemes: a scanner reads dark on
                light, and the person at this screen cannot work around a code
                their camera will not see.
            --}}
            <figure class="mt-4 flex justify-center">
                <div class="rounded-xl bg-white p-3" role="img"
                     aria-label="{{ __('sanabel.two_factor.qr_alt') }}">
                    {!! $this->qrSvg() !!}
                </div>
            </figure>

            <div class="mt-6 border-t border-gray-200 pt-4 dark:border-gray-700">
                <p class="text-sm font-medium">{{ __('sanabel.two_factor.manual_heading') }}</p>

                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                    {{ __('sanabel.two_factor.manual_help') }}
                </p>

                <p class="mt-2 select-all font-mono text-lg tracking-widest" dir="ltr">{{ $pendingSecret }}</p>
            </div>
        </x-filament::section>
    @endif

    <form wire:submit="confirm" class="mt-6 space-y-6">
        {{ $this->form }}

        <x-filament::button type="submit">
            {{ __('sanabel.two_factor.confirm') }}
        </x-filament::button>
    </form>
</x-filament-panels::page>
