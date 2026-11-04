{{--
    T-242 — the gift page, in the association's own ten steps.

    One card to begin with and another on a button; each card carries its own
    destination, design, two names, number, amount, whether the amount is
    shown, and whether it goes now or at an hour. Under them, the one transfer
    that pays for all of them — the same transfer, the same reference, the
    same verification as every other donation here.
--}}
<div class="mx-auto max-w-2xl">
    <h1 class="text-xl">{{ __('sanabel.gift.page_title') }}</h1>
    <p class="mt-2 text-sm leading-relaxed" style="color: var(--text-muted);">
        {{ __('sanabel.gift.page_body') }}
    </p>

    @if ($notice)
        <div class="card mt-5 text-center" x-data x-init="$nextTick(() => $el.scrollIntoView({ behavior: 'smooth', block: 'center' }))">
            <p class="font-medium" style="color: var(--accent);">{{ $notice }}</p>

            @if ($thanks)
                <p class="mt-3 text-sm leading-loose">{{ __('sanabel.public.thanks_blessing') }}</p>
            @endif
        </div>
    @endif

    @if ($error)
        <p class="mt-4 text-sm text-red-600 dark:text-red-400" x-data x-init="$nextTick(() => $el.scrollIntoView({ behavior: 'smooth', block: 'center' }))">{{ $error }}</p>
    @endif

    @if ($designs->isEmpty())
        {{-- The designs are the association's own artwork. Until they upload
             one there is no card to give, and a page that pretends otherwise
             would issue a gift with nothing on it. --}}
        <p class="card mt-5 text-sm">{{ __('sanabel.gift.no_designs') }}</p>
    @else
        <form wire:submit="record" class="mt-5 space-y-5">
            @foreach ($cards as $i => $card)
                <div class="card space-y-4" wire:key="gift-card-{{ $i }}">
                    <div class="flex items-center justify-between">
                        <h2 class="font-medium">{{ __('sanabel.gift.card_no', ['no' => $i + 1]) }}</h2>

                        @if (count($cards) > 1)
                            <button type="button" wire:click="removeCard({{ $i }})"
                                    class="text-xs text-red-600 dark:text-red-400">
                                {{ __('sanabel.gift.remove_card') }}
                            </button>
                        @endif
                    </div>

                    {{-- ١ — إهداء عام أو إهداء مخصص لمشروع --}}
                    <div>
                        <span class="field-label">{{ __('sanabel.gift.destination') }}</span>
                        <div class="mt-1 flex flex-wrap gap-4 text-sm">
                            <label class="flex items-center gap-2">
                                <input type="radio" value="general" wire:model.live="cards.{{ $i }}.kind">
                                {{ __('sanabel.gift.general') }}
                            </label>
                            <label class="flex items-center gap-2">
                                <input type="radio" value="project" wire:model.live="cards.{{ $i }}.kind">
                                {{ __('sanabel.gift.to_project') }}
                            </label>
                        </div>
                    </div>

                    {{-- ٣ — اختيار المشروع عند الإهداء المخصص --}}
                    @if (($card['kind'] ?? 'general') === 'project')
                        <div>
                            <label class="field-label" for="gift-project-{{ $i }}">{{ __('sanabel.gift.project') }}</label>
                            <select id="gift-project-{{ $i }}" wire:model="cards.{{ $i }}.campaign_id" class="field">
                                <option value="">{{ __('sanabel.gift.choose_project') }}</option>
                                @foreach ($projects as $id => $title)
                                    <option value="{{ $id }}">{{ $title }}</option>
                                @endforeach
                            </select>
                            @error("cards.$i.campaign_id")
                                <p class="mt-1.5 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>
                    @endif

                    {{-- ٢ — اختيار بطاقة الإهداء من التصاميم المعتمدة --}}
                    <div>
                        <span class="field-label">{{ __('sanabel.gift.card') }}</span>
                        <div class="mt-2 grid grid-cols-2 gap-3 sm:grid-cols-3">
                            @foreach ($designs as $design)
                                <label class="cursor-pointer overflow-hidden rounded-xl border p-1 text-center text-xs"
                                       style="border-color: {{ (int) ($card['gift_card_id'] ?? 0) === $design->id ? 'var(--accent)' : 'var(--line)' }};">
                                    <input type="radio" class="sr-only" value="{{ $design->id }}"
                                           wire:model.live="cards.{{ $i }}.gift_card_id">
                                    @if ($design->imageUrl())
                                        <img src="{{ $design->imageUrl() }}" alt="{{ $design->name_ar }}"
                                             class="h-24 w-full rounded-lg object-cover">
                                    @endif
                                    <span class="mt-1 block">{{ $design->name_ar }}</span>
                                </label>
                            @endforeach
                        </div>
                        @error("cards.$i.gift_card_id")
                            <p class="mt-1.5 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- ٤ — اسم المُهدي واسم المُهدى إليه ورقم الهاتف مع مفتاح الدولة --}}
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="field-label" for="gift-giver-{{ $i }}">{{ __('sanabel.gift.giver') }}</label>
                            <input id="gift-giver-{{ $i }}" type="text" wire:model="cards.{{ $i }}.giver_name_ar" class="field">
                            @error("cards.$i.giver_name_ar")
                                <p class="mt-1.5 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label class="field-label" for="gift-recipient-{{ $i }}">{{ __('sanabel.gift.recipient') }}</label>
                            <input id="gift-recipient-{{ $i }}" type="text" wire:model="cards.{{ $i }}.recipient_name_ar" class="field">
                            @error("cards.$i.recipient_name_ar")
                                <p class="mt-1.5 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-3">
                        <div>
                            <label class="field-label" for="gift-key-{{ $i }}">{{ __('sanabel.gift.country_code') }}</label>
                            <input id="gift-key-{{ $i }}" type="text" dir="ltr"
                                   wire:model="cards.{{ $i }}.recipient_country_code" class="field tabular">
                            @error("cards.$i.recipient_country_code")
                                <p class="mt-1.5 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="sm:col-span-2">
                            <label class="field-label" for="gift-phone-{{ $i }}">{{ __('sanabel.gift.phone') }}</label>
                            <input id="gift-phone-{{ $i }}" type="tel" dir="ltr"
                                   wire:model="cards.{{ $i }}.recipient_phone" class="field tabular">
                            @error("cards.$i.recipient_phone")
                                <p class="mt-1.5 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    {{-- ٥ — مبلغ جاهز أو مبلغ مخصص --}}
                    <div>
                        <label class="field-label" for="gift-amount-{{ $i }}">{{ __('sanabel.gift.amount') }}</label>

                        @if ($amounts !== [])
                            <div class="mb-2 flex flex-wrap gap-2">
                                @foreach ($amounts as $ready)
                                    <button type="button" class="btn-secondary tabular text-xs"
                                            wire:click="$set('cards.{{ $i }}.amount', {{ $ready }})">
                                        {{ number_format($ready) }}
                                    </button>
                                @endforeach
                            </div>
                        @endif

                        <input id="gift-amount-{{ $i }}" type="number" min="1" dir="ltr"
                               wire:model.live="cards.{{ $i }}.amount" class="field tabular"
                               placeholder="{{ __('sanabel.gift.custom_amount') }}">
                        @error("cards.$i.amount")
                            <p class="mt-1.5 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- ٦ — إظهار المبلغ للمُهدى إليه أو إخفاؤه --}}
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" wire:model="cards.{{ $i }}.show_amount">
                        {{ __('sanabel.gift.show_amount') }}
                    </label>

                    {{-- ٨ — إهداء مباشر أو مجدول بتاريخ ووقت --}}
                    <div>
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" wire:model.live="cards.{{ $i }}.schedule">
                            {{ __('sanabel.gift.schedule') }}
                        </label>

                        {{-- T-244 — the reminder at the hour goes to an account,
                             and a guest has none. Said here, before the box is
                             ticked, rather than after the form is filled. --}}
                        @unless (auth()->user()?->donor)
                            <p class="mt-1 text-xs" style="color: var(--text-muted);">
                                {{ __('sanabel.gift.schedule_needs_account') }}
                            </p>
                        @endunless

                        @if ($card['schedule'] ?? false)
                            <input type="datetime-local" dir="ltr" class="field mt-2"
                                   wire:model="cards.{{ $i }}.send_at">
                            @error("cards.$i.send_at")
                                <p class="mt-1.5 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        @else
                            <p class="mt-1 text-xs" style="color: var(--text-muted);">
                                {{ __('sanabel.gift.send_now_help') }}
                            </p>
                        @endif
                    </div>
                </div>
            @endforeach

            {{-- ٧ — أكثر من بطاقة إهداء ضمن العملية نفسها --}}
            <button type="button" wire:click="addCard" class="btn-secondary w-full justify-center">
                {{ __('sanabel.gift.add_card') }}
            </button>

            <div class="card space-y-4">
                <p class="flex items-center justify-between font-medium">
                    <span>{{ __('sanabel.gift.total') }}</span>
                    <span class="tabular">{{ number_format($this->total) }}</span>
                </p>

                {{-- No automatic transfers exist: a human moves the money and
                     the system records it (rule 7). --}}
                @if ($transferWallet)
                    <x-transfer-wallet :wallet="$transferWallet" />
                @else
                    <p class="text-sm" style="color: var(--text-muted);">{{ __('sanabel.transfer.none') }}</p>
                @endif

                @unless (auth()->user()?->donor)
                    <div>
                        <label class="field-label" for="gift-name">{{ __('sanabel.donation.donor') }}</label>
                        <input id="gift-name" type="text" wire:model="name" class="field">
                        @error('name')
                            <p class="mt-1.5 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label class="field-label" for="gift-donor-phone">
                            {{ __('sanabel.donor.phone') }}
                            <span class="font-normal" style="color: var(--text-muted);">{{ __('sanabel.optional') }}</span>
                        </label>
                        <input id="gift-donor-phone" type="tel" dir="ltr" wire:model="phone" class="field tabular">
                        @error('phone')
                            <p class="mt-1.5 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>
                @endunless

                <div>
                    <label class="field-label" for="gift-ref">{{ __('sanabel.donation.transaction_ref') }}</label>
                    <input id="gift-ref" type="text" dir="ltr" wire:model="transactionRef" class="field tabular">
                    @error('transactionRef')
                        <p class="mt-1.5 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="field-label" for="gift-receipt">{{ __('sanabel.donation.receipt_upload') }}</label>
                    <input id="gift-receipt" type="file" wire:model="receipt" class="field"
                           accept="image/*,application/pdf">
                    <p class="mt-1 text-xs" wire:loading wire:target="receipt">…</p>
                    @error('receipt')
                        <p class="mt-1.5 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                {{-- ٩ — the card is issued after the transfer is verified, and
                     the donor is told so here rather than discovering it. --}}
                <p class="text-xs leading-relaxed" style="color: var(--text-muted);">
                    {{ __('sanabel.gift.issued_after_verification') }}
                </p>

                <button type="submit" class="btn-primary w-full justify-center">
                    <span wire:loading.remove wire:target="record">{{ __('sanabel.gift.submit') }}</span>
                    <span wire:loading wire:target="record">…</span>
                </button>
            </div>
        </form>
    @endif
</div>
