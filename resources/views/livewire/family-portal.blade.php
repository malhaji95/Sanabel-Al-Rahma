<div>
    @if (! $family)
        <x-empty-state :title="__('sanabel.portal.no_family_file')" />
    @else
        <div class="mb-6">
            <h1 class="text-2xl">{{ __('sanabel.portal.my_file') }}</h1>
            <p class="mt-1 text-sm tabular" dir="ltr" style="color: var(--text-muted);">{{ $family->file_number }}</p>
        </div>

        @if ($notice)
            <div class="mb-4 rounded-xl border p-3 text-sm"
                 style="border-color: var(--brand-200); background-color: var(--brand-50);">{{ $notice }}</div>
        @endif

        @if ($error)
            <div class="mb-4 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-800">{{ $error }}</div>
        @endif

        <div class="grid gap-4 sm:grid-cols-2">
            <div class="card">
                <p class="text-sm" style="color: var(--text-muted);">{{ __('sanabel.portal.monthly_need') }}</p>
                <p class="tabular text-2xl font-bold">{{ number_format($need) }}
                    <span class="text-sm font-normal">{{ config('sanabel.currency') }}</span></p>
            </div>

            <div class="card">
                <p class="text-sm" style="color: var(--text-muted);">{{ __('sanabel.beneficiary.coverage') }}</p>
                <p class="tabular text-2xl font-bold">{{ $coverage }}%</p>
            </div>
        </div>

        <h2 class="mt-8 text-lg font-semibold">{{ __('sanabel.portal.payments') }}</h2>

        @if ($payments->isEmpty())
            <x-empty-state :title="__('sanabel.portal.no_payments')" />
        @else
            <div class="mt-3 space-y-3">
                @foreach ($payments as $payment)
                    <div class="card">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p class="tabular text-lg font-bold">{{ number_format($payment->amount) }}
                                    <span class="text-sm font-normal">{{ config('sanabel.currency') }}</span></p>
                                <p class="mt-1 text-sm" style="color: var(--text-muted);">
                                    {{ __('sanabel.disbursement.transfer_ref') }}:
                                    <span class="tabular" dir="ltr">{{ $payment->transfer_ref ?? '—' }}</span>
                                </p>
                                <p class="text-sm" style="color: var(--text-muted);">
                                    {{ $payment->executed_at?->translatedFormat('Y-m-d') }}
                                </p>
                            </div>

                            <span class="badge">{{ __('sanabel.disbursement.statuses.' . $payment->status) }}</span>
                        </div>

                        @if ($payment->awaitsBeneficiary())
                            {{-- The association's own wording, shown before the family worries. --}}
                            <p class="mt-3 text-sm">{{ __('sanabel.disbursement.deposit_notice') }}</p>
                            <p class="mt-1 text-xs" style="color: var(--text-muted);">
                                {{ __('sanabel.disbursement.confirm_window', ['days' => $confirmDays]) }}
                            </p>

                            <div class="mt-3 flex flex-wrap gap-2">
                                <button type="button" class="btn-primary"
                                        wire:click="confirm({{ $payment->id }})">
                                    {{ __('sanabel.disbursement.confirm_receipt') }}
                                </button>

                                <button type="button" class="btn-secondary"
                                        wire:click="$set('disputing', {{ $payment->id }})">
                                    {{ __('sanabel.disbursement.dispute_receipt') }}
                                </button>
                            </div>

                            @if ($disputing === $payment->id)
                                <div class="mt-3">
                                    <label class="field-label" for="reason-{{ $payment->id }}">
                                        {{ __('sanabel.disbursement.dispute_hint') }}
                                    </label>
                                    <textarea id="reason-{{ $payment->id }}" wire:model="reason"
                                              rows="3" class="field"></textarea>
                                    @error('reason')
                                        <p class="mt-1 text-xs text-red-700">{{ $message }}</p>
                                    @enderror

                                    <button type="button" class="btn-primary mt-2"
                                            wire:click="dispute({{ $payment->id }})">
                                        {{ __('sanabel.actions.send') }}
                                    </button>
                                </div>
                            @endif
                        @endif

                        @if ($payment->status === 'disputed' && $payment->complaint_id)
                            <p class="mt-2 text-sm" style="color: var(--text-muted);">
                                {{ __('sanabel.complaint.reference_no') }}:
                                <span class="tabular" dir="ltr">{{ $payment->complaint?->reference_no }}</span>
                            </p>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    @endif
</div>
