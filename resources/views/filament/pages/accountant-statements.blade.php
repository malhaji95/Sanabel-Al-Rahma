<x-filament-panels::page>
    {{--
        T-322 — «معاينة قبل الإدخال». Every line and the verdict the matching
        reached, before anything is approved: a reconciliation nobody read is
        a reconciliation nobody checked.
    --}}
    @forelse ($this->statements() as $import)
        <x-filament::section
            :heading="$import->original_name"
            :description="__('sanabel.statement.statuses.'.$import->status)"
        >
            <x-slot name="headerEnd">
                @if ($import->isStaged())
                    <div class="flex gap-2">
                        <x-filament::button
                            size="sm"
                            wire:click="approve({{ $import->id }})"
                            wire:confirm="{{ __('sanabel.statement.approve_help') }}"
                        >
                            {{ __('sanabel.statement.approve') }}
                        </x-filament::button>
                    </div>
                @endif
            </x-slot>

            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('sanabel.statement.uploaded_by') }}: {{ $import->uploader?->name ?? '—' }}
                · {{ $import->uploaded_at?->format('Y-m-d H:i') }}
                @if ($import->approved_at)
                    · {{ __('sanabel.statement.approved_by') }}: {{ $import->approver?->name ?? '—' }}
                    · {{ $import->approved_at->format('Y-m-d H:i') }}
                @endif
            </p>

            @if ($import->discard_reason_ar)
                <p class="mt-2 text-sm">{{ $import->discard_reason_ar }}</p>
            @endif

            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-start">
                            <th class="p-2 text-start">{{ __('sanabel.statement.row_no') }}</th>
                            <th class="p-2 text-start">{{ __('sanabel.donation.transaction_ref') }}</th>
                            <th class="p-2 text-start">{{ __('sanabel.donation.amount') }}</th>
                            <th class="p-2 text-start">{{ __('sanabel.statement.uploaded_at') }}</th>
                            <th class="p-2 text-start">{{ __('sanabel.beneficiary.status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($import->rows as $row)
                            <tr class="border-t border-gray-200 dark:border-gray-700">
                                <td class="p-2 tabular-nums">{{ $row->row_no }}</td>
                                <td class="p-2" dir="ltr">{{ $row->transaction_ref ?? '—' }}</td>
                                <td class="p-2 tabular-nums">
                                    {{ $row->amount === null ? '—' : number_format($row->amount) }}
                                    {{ $row->currency }}
                                </td>
                                <td class="p-2 tabular-nums">{{ $row->received_on?->format('Y-m-d') ?? '—' }}</td>
                                <td class="p-2">
                                    <x-filament::badge
                                        :color="$row->outcome === 'ready' ? 'success' : ($row->outcome === 'already_verified' ? 'gray' : 'warning')"
                                    >
                                        {{ __('sanabel.statement.outcomes.'.$row->outcome) }}
                                    </x-filament::badge>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @empty
        <x-filament::section>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('sanabel.statement.none') }}
            </p>
        </x-filament::section>
    @endforelse
</x-filament-panels::page>
