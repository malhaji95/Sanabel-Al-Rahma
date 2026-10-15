{{--
    T-103 — one screen between a donor and giving. No file to pick, no basket,
    no reservation: an amount, the reference of the transfer they made, and
    the association's own wallet to make it to.

    The destination is the same wallet every other donation reaches. A
    household's account is never on this page, and the sentence under it says
    so rather than leaving a donor to assume.
--}}
<div class="mx-auto max-w-xl">
    <h1 class="text-xl">{{ __('sanabel.public.general_donation') }}</h1>
    <p class="mt-2 text-sm leading-relaxed" style="color: var(--text-muted);">
        {{ __('sanabel.public.general_donation_body') }}
    </p>

    @if ($notice)
        <div class="card mt-5 text-center">
            <p class="font-medium" style="color: var(--accent);">{{ $notice }}</p>

            @if ($thanks)
                <p class="mt-3 text-sm leading-loose">{{ __('sanabel.public.thanks_blessing') }}</p>
                <p class="mt-1 text-sm" style="color: var(--text-muted);">{{ __('sanabel.public.thanks_trust') }}</p>
            @endif

            <a href="{{ route('cases.browse') }}" class="btn-secondary mt-5 inline-block no-underline">
                {{ __('sanabel.public.browse_cases') }}
            </a>
        </div>
    @endif

    @if ($error)
        <p class="mt-4 text-sm text-red-600 dark:text-red-400">{{ $error }}</p>
    @endif

    @unless ($hasDonorAccount)
        <p class="card mt-5 text-sm" style="color: var(--text-muted);">
            {{ __('sanabel.donation.no_donor_account') }}
        </p>
    @else
        <div class="card mt-5">
            {{-- No automatic transfers exist: a human moves the money, the
                 system records it (rule 7). --}}
            <form wire:submit="record" class="space-y-4">
                @if ($transferWallet)
                    <div>
                        <p class="text-sm font-medium">{{ __('sanabel.transfer.single') }}</p>

                        <div class="mt-2 rounded-lg border p-4" style="border-color: var(--border);">
                            <p class="text-sm font-semibold" style="color: var(--brand-primary);">
                                {{ __('sanabel.transfer.destination') }}
                            </p>
                            <p class="tabular mt-2 text-lg font-bold" dir="ltr">{{ $transferWallet['wallet'] }}</p>
                            <p class="mt-1 text-xs" style="color: var(--text-muted);">
                                {{ __('sanabel.transfer.holder') }}: {{ $transferWallet['holder'] }}
                            </p>
                        </div>
                    </div>
                @else
                    <p class="text-sm" style="color: var(--text-muted);">{{ __('sanabel.transfer.none') }}</p>
                @endif

                <div>
                    <label class="field-label" for="general-amount">{{ __('sanabel.donation.amount') }}</label>
                    <input id="general-amount" type="number" min="1" wire:model="amount" class="field tabular" dir="ltr">
                    @error('amount')
                        <p class="mt-1.5 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="field-label" for="general-ref">{{ __('sanabel.donation.transaction_ref') }}</label>
                    <input id="general-ref" type="text" wire:model="transactionRef" class="field tabular" dir="ltr">
                    @error('transactionRef')
                        <p class="mt-1.5 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                <div class="rounded-xl p-4 text-center leading-loose"
                     style="background: var(--surface-muted, rgba(0,0,0,.03));">
                    <p class="font-medium">{{ __('sanabel.public.nudge_title') }}</p>
                    <p class="mt-1 text-sm" style="color: var(--text-muted);">{{ __('sanabel.public.nudge_hadith') }}</p>
                    <p class="mt-1 text-xs" style="color: var(--text-muted);">{{ __('sanabel.public.nudge_source') }}</p>
                </div>

                <button type="submit" class="btn-primary w-full justify-center">
                    <span wire:loading.remove wire:target="record">{{ __('sanabel.public.record_transfer') }}</span>
                    <span wire:loading wire:target="record">…</span>
                </button>
            </form>
        </div>
    @endunless

    <p class="mt-5 text-center text-sm">
        <a href="{{ route('cases.browse') }}" style="color: var(--accent);">
            {{ __('sanabel.public.general_donation_or_pick') }}
        </a>
    </p>
</div>
