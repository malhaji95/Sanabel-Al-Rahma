{{--
    T-121 — «موقع السكن خط العرض وخط الطول مو واضح معنا وما بيدخل», 25 October.

    The same control the delegate already has on the visit screen: a button
    that asks the device where it is, a line that says whether a location was
    recorded, and a way to clear it. No number is typed, so no keyboard and no
    decimal separator can get in the way.

    The two values are written into the form's own hidden fields, so what is
    saved is unchanged and the page needs nothing new behind it.
--}}
@php
    $container = \Illuminate\Support\Str::beforeLast($getStatePath(), '.');
@endphp

<div
    x-data="{
        locating: false,
        denied: false,
        get located() {
            return !! $wire.$get(@js($container . '.latitude'));
        },
        locate() {
            if (! navigator.geolocation) {
                this.denied = true;

                return;
            }

            this.locating = true;
            this.denied = false;

            navigator.geolocation.getCurrentPosition(
                (position) => {
                    $wire.$set(@js($container . '.latitude'), position.coords.latitude.toFixed(6));
                    $wire.$set(@js($container . '.longitude'), position.coords.longitude.toFixed(6));
                    this.locating = false;
                },
                () => {
                    this.denied = true;
                    this.locating = false;
                },
                { enableHighAccuracy: true, timeout: 15000 },
            );
        },
        clear() {
            $wire.$set(@js($container . '.latitude'), null);
            $wire.$set(@js($container . '.longitude'), null);
        },
    }"
    class="fi-fo-field-wrp"
>
    <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <span class="text-sm font-medium">{{ __('sanabel.beneficiary.location') }}</span>

            <div class="flex items-center gap-2">
                <x-filament::button type="button" color="gray" size="sm" x-on:click="locate()" x-bind:disabled="locating">
                    <span x-text="locating ? @js(__('sanabel.field.locating')) : @js(__('sanabel.field.locate'))"></span>
                </x-filament::button>

                <button type="button" class="text-xs underline" x-show="located" x-cloak x-on:click="clear()">
                    {{ __('sanabel.field.clear_location') }}
                </button>
            </div>
        </div>

        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400"
           x-text="located ? @js(__('sanabel.beneficiary.location_set')) : @js(__('sanabel.beneficiary.location_help'))"></p>

        <p class="mt-2 text-xs text-danger-600" x-show="denied" x-cloak>
            {{ __('sanabel.beneficiary.location_denied') }}
        </p>
    </div>
</div>
