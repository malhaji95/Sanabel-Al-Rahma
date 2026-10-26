<div class="space-y-6">
    <div>
        <h1 class="text-2xl">{{ __('sanabel.public.basket') }}</h1>
        @if ($items->isNotEmpty())
            <p class="mt-1 text-sm" style="color: var(--text-muted);">
                {{ __('sanabel.public.basket_count', ['count' => $items->count()]) }}
            </p>
        @endif
    </div>

    @if ($error)
        <div class="alert-danger" role="alert" x-data x-init="$nextTick(() => $el.scrollIntoView({ behavior: 'smooth', block: 'center' }))">
            <svg class="mt-0.5 h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01" stroke-linecap="round"/>
            </svg>
            <span>{{ $error }}</span>
        </div>
    @endif

    @if ($notice)
        <div class="alert-success" role="status" x-data x-init="$nextTick(() => $el.scrollIntoView({ behavior: 'smooth', block: 'center' }))">
            <svg class="mt-0.5 h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <path d="m5 13 4 4L19 7" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <span>{{ $notice }}</span>
        </div>
    @endif

    @if ($thanks)
        <div class="card my-4 text-center leading-loose">
            <p>{{ __('sanabel.public.thanks_blessing') }}</p>
            <p class="mt-2" style="color: var(--text-muted);">{{ __('sanabel.public.thanks_trust') }}</p>
        </div>
    @endif

    @if ($items->isEmpty())
        <x-empty-state :title="__('sanabel.basket.empty')" :body="__('sanabel.public.basket_empty_help')">
            <a href="{{ route('cases.browse') }}" class="btn-primary mt-2 no-underline">
                {{ __('sanabel.public.browse_cases') }}
            </a>
        </x-empty-state>
    @else
        {{-- T-147 — «ومانحتاح في السلة تظهر كامل الحاله، يكفي يظهر رقم الملف
             المبلغ المتبرع به مع الاستطاعه انه يخفض المبلغ او يزيده مع نسبة
             التغطيه … مثل سلة المشتريات», 29 October.

             So a line, not a card: the file's number, what is being given to
             it, and how far its month has come. The family's own page is one
             click away for anyone who wants the rest of it. --}}
        <div class="card">
            @foreach ($items as $item)
                <div
                    class="flex flex-wrap items-center gap-x-6 gap-y-3 border-t py-4 first:border-t-0 first:pt-0 last:pb-0"
                    style="border-color: var(--border);"
                >
                    <div class="min-w-48 flex-1">
                        @if ($item['campaign'])
                            <p class="font-semibold" style="color: var(--accent);">{{ $item['campaign'] }}</p>
                        @endif

                        <a href="{{ route('opportunity', $item['case']['file_number']) }}"
                           class="font-semibold no-underline hover:underline">
                            {{ __('sanabel.beneficiary.file_number') }}
                            <span class="tabular" dir="ltr">{{ $item['case']['file_number'] }}</span>
                        </a>

                        <p class="mt-0.5 text-xs" style="color: var(--text-muted);">
                            {{ __('sanabel.funding.covers_month', ['month' => $item['case']['coverage_month_label']]) }}
                        </p>
                    </div>

                    <div class="w-36">
                        <label class="field-label !mb-1 text-xs" for="amount-{{ $item['id'] }}">
                            {{ __('sanabel.public.amount') }}
                        </label>
                        <input
                            id="amount-{{ $item['id'] }}"
                            type="number"
                            wire:model.live.debounce.600ms="amounts.{{ $item['id'] }}"
                            min="1"
                            max="{{ $item['ceiling'] }}"
                            inputmode="numeric"
                            class="field tabular"
                        >
                        <p class="mt-1 text-xs" style="color: var(--text-muted);">{{ \App\Services\Money::label() }}</p>
                    </div>

                    <div class="w-40">
                        <p class="text-xs" style="color: var(--text-muted);">
                            {{ __('sanabel.beneficiary.coverage') }}
                            <span class="tabular font-semibold" style="color: var(--text);">{{ $item['case']['coverage_percent'] }}%</span>
                        </p>

                        <div
                            class="meter mt-1.5"
                            role="progressbar"
                            aria-valuenow="{{ $item['case']['coverage_percent'] }}"
                            aria-valuemin="0"
                            aria-valuemax="100"
                            aria-label="{{ __('sanabel.beneficiary.coverage') }}"
                        >
                            @if ($item['case']['coverage_percent'] > 0)
                                <div
                                    class="meter-fill meter-fill-{{ \App\Services\ProgressBand::of($item['case']['coverage_percent']) }}"
                                    style="width: {{ $item['case']['coverage_percent'] }}%"
                                ></div>
                            @endif
                        </div>
                    </div>

                    <button
                        type="button"
                        wire:click="removeItem({{ $item['id'] }})"
                        class="btn-ghost !px-2 !py-1 text-sm hover:!text-red-600"
                    >{{ __('sanabel.public.remove') }}</button>
                </div>
            @endforeach
        </div>

        <div class="card space-y-5">
            <div class="flex items-baseline justify-between">
                <span class="text-lg font-medium">{{ __('sanabel.public.total') }}</span>
                <span class="tabular text-2xl font-bold" style="color: var(--accent);">
                    {{ number_format($total) }}
                    <span class="text-sm font-normal" style="color: var(--text-muted);">{{ \App\Services\Money::label() }}</span>
                </span>
            </div>

            @if ($basket->isLive())
                <div class="alert-warning" role="status">
                    <svg class="mt-0.5 h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2" stroke-linecap="round"/>
                    </svg>
                    <span>{{ __('sanabel.public.reserved_until', ['time' => $basket->reserved_until->translatedFormat('Y-m-d H:i')]) }}</span>
                </div>

                {{-- No automatic transfers exist: a human moves the money, the system records it. --}}
                <form wire:submit="recordTransfer" class="space-y-4">
                    {{-- One destination for everything in the basket: Sanabel
                         Al-Wafa's own wallet. A family's account is never here. --}}
                    @if ($transferWallet)
                        <x-transfer-wallet :wallet="$transferWallet" />
                    @else
                        <p class="text-sm" style="color: var(--text-muted);">{{ __('sanabel.transfer.none') }}</p>
                    @endif

                    <div>
                        <label class="field-label" for="transaction-ref">
                            {{ __('sanabel.donation.transaction_ref') }}
                        </label>
                        <input id="transaction-ref" type="text" wire:model="transactionRef" class="field tabular" dir="ltr">
                        @error('transactionRef')
                            <p class="mt-1.5 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- T-123 — «لازم يتم رفع صورة الحوالة سواء pdf او أي صورة». --}}
                    <div>
                        <label class="field-label" for="basket-receipt">
                            {{ __('sanabel.donation.receipt_upload') }}
                        </label>

                        <input id="basket-receipt" type="file" wire:model="receipt" class="field"
                               accept="image/*,application/pdf">

                        <p class="mt-1.5 text-xs" style="color: var(--text-muted);">
                            {{ __('sanabel.donation.receipt_help') }}
                        </p>

                        <p class="mt-1 text-xs" wire:loading wire:target="receipt">…</p>

                        @error('receipt')
                            <p class="mt-1.5 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- Where a shop puts its coupon box. --}}
                    <div class="rounded-xl p-4 text-center leading-loose"
                         style="background: var(--surface-muted, rgba(0,0,0,.03));">
                        <p class="font-medium">{{ __('sanabel.public.nudge_title') }}</p>
                        <p class="mt-1 text-sm" style="color: var(--text-muted);">{{ __('sanabel.public.nudge_hadith') }}</p>
                        <p class="mt-1 text-xs" style="color: var(--text-muted);">{{ __('sanabel.public.nudge_source') }}</p>
                    </div>

                    <button type="submit" class="btn-primary">
                        <span wire:loading.remove wire:target="recordTransfer">{{ __('sanabel.public.record_transfer') }}</span>
                        <span wire:loading wire:target="recordTransfer">…</span>
                    </button>
                </form>
            @else
                <div>
                    <button type="button" wire:click="reserve" class="btn-primary">
                        {{ __('sanabel.public.reserve') }}
                    </button>
                    <p class="mt-2.5 text-sm" style="color: var(--text-muted);">{{ __('sanabel.public.reserve_help') }}</p>
                </div>
            @endif
        </div>
    @endif
</div>
