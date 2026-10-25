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
                    <span class="text-sm font-normal">{{ \App\Services\Money::label() }}</span></p>
            </div>

            <div class="card">
                <p class="text-sm" style="color: var(--text-muted);">{{ __('sanabel.beneficiary.coverage') }}</p>
                <p class="tabular text-2xl font-bold">{{ $coverage }}%</p>
            </div>
        </div>

        {{-- The account the association pays into, so the family can tell the
             desk if it is wrong before the money moves (12 Oct 2026). --}}
        <div class="card mt-4">
            <p class="text-sm" style="color: var(--text-muted);">{{ __('sanabel.portal.my_wallet') }}</p>
            @if ($wallet)
                <p class="tabular mt-1 text-lg font-semibold" dir="ltr">{{ $wallet }}</p>
                <p class="mt-1 text-sm" style="color: var(--text-muted);">{{ __('sanabel.portal.my_wallet_check') }}</p>
            @else
                <p class="mt-1 text-sm">{{ __('sanabel.portal.no_wallet') }}</p>
            @endif
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
                                    <span class="text-sm font-normal">{{ \App\Services\Money::label() }}</span></p>
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
                                @if ($payment->awaitsAndIsOverdue())
                                    {{ __('sanabel.disbursement.still_awaiting_you') }}
                                @else
                                    {{ __('sanabel.disbursement.confirm_window', ['days' => $confirmDays]) }}
                                @endif
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

        {{-- T-145 — المسار الصحي: تقديم الأسرة، وجواب الجمعية عليه. --}}
        <div class="card">
            <h2 class="text-lg font-semibold">{{ __('sanabel.health_path.apply_title') }}</h2>
            <p class="mt-1 text-sm leading-relaxed" style="color: var(--text-muted);">
                {{ __('sanabel.health_path.apply_body') }}
            </p>

            <form wire:submit="applyForHealthPath" class="mt-4 grid gap-3 sm:grid-cols-2">
                <label class="block text-sm">
                    <span class="field-label">{{ __('sanabel.health_path.provider_type') }}</span>
                    <select wire:model="healthType" class="field mt-1">
                        <option value="">—</option>
                        @foreach ($healthTypes as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('healthType')
                        <span class="mt-1 block text-xs text-red-700">{{ $message }}</span>
                    @enderror
                </label>

                <label class="block text-sm">
                    <span class="field-label">{{ __('sanabel.health_path.specialty') }}</span>
                    <input type="text" wire:model="healthSpecialty" class="field mt-1">
                </label>

                <label class="block text-sm sm:col-span-2">
                    <span class="field-label">{{ __('sanabel.health_path.note') }}</span>
                    <textarea wire:model="healthNote" rows="3" class="field mt-1"></textarea>
                    <span class="mt-1 block text-xs" style="color: var(--text-muted);">
                        {{ __('sanabel.health_path.note_help') }}
                    </span>
                </label>

                <div class="sm:col-span-2">
                    <button type="submit" class="btn-primary">
                        <span wire:loading.remove wire:target="applyForHealthPath">
                            {{ __('sanabel.health_path.apply') }}
                        </span>
                        <span wire:loading wire:target="applyForHealthPath">…</span>
                    </button>
                </div>
            </form>

            @if ($healthRequests->isNotEmpty())
                <h3 class="mt-6 text-sm font-semibold">{{ __('sanabel.health_path.my_requests') }}</h3>

                <ul class="mt-2 space-y-3">
                    @foreach ($healthRequests as $request)
                        <li class="rounded-xl border p-3 text-sm" style="border-color: var(--border);">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <span class="font-medium">
                                    {{ __('sanabel.provider_type.' . $request->provider_type) }}
                                    @if ($request->specialty_ar)
                                        — {{ $request->specialty_ar }}
                                    @endif
                                </span>

                                <span class="badge">
                                    {{ __('sanabel.health_path.statuses.' . $request->status) }}
                                </span>
                            </div>

                            @if ($request->status === 'issued' && $request->referral)
                                <p class="mt-2">
                                    <span style="color: var(--text-muted);">{{ __('sanabel.health_path.card_code') }}:</span>
                                    <span class="tabular font-bold" dir="ltr">{{ $request->referral->code }}</span>
                                </p>
                                <p class="mt-1 text-xs" style="color: var(--text-muted);">
                                    {{ __('sanabel.health_path.card_expires') }}:
                                    {{ $request->referral->expires_at?->format('Y-m-d') }}
                                    — {{ __('sanabel.health_path.card_help') }}
                                </p>
                            @endif

                            @if ($request->status === 'declined' && $request->decline_reason_ar)
                                <p class="mt-2 text-sm">{{ $request->decline_reason_ar }}</p>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

</div>
