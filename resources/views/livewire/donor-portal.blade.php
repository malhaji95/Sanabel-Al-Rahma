<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <h1 class="text-2xl">{{ __('sanabel.public.my_donations') }}</h1>

        @if ($donor)
            <div class="flex items-center gap-2.5 text-sm">
                <span @class([
                    'badge gap-1.5',
                    'bg-gold-100 text-gold-800 dark:bg-gold-900/50 dark:text-gold-100' => $donor->badge === 'gold',
                    'bg-slate-200 text-slate-700 dark:bg-slate-700 dark:text-slate-100' => $donor->badge === 'silver',
                    'bg-brand-50 text-brand-700 dark:bg-brand-900/50 dark:text-brand-100' => $donor->badge === 'none',
                ])>
                    @if ($donor->badge !== 'none')
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="m12 2 2.9 6.1 6.6.9-4.8 4.6 1.2 6.6L12 17.1 6.1 20.2l1.2-6.6L2.5 9l6.6-.9L12 2Z"/>
                        </svg>
                    @endif
                    {{ __('sanabel.badge.' . $donor->badge) }}
                </span>
                <span style="color: var(--text-muted);">
                    {{ __('sanabel.public.verified_donations', ['count' => $donor->donations_count]) }}
                </span>
            </div>
        @endif
    </div>

    <div class="flex flex-wrap gap-3">
        <a href="{{ route('cases.browse') }}" class="btn-primary no-underline">{{ __('sanabel.public.cases') }}</a>
        <a href="{{ route('donor.basket') }}" class="btn-secondary no-underline">{{ __('sanabel.public.basket') }}</a>
    </div>

    @if ($donations->isEmpty())
        <x-empty-state :title="__('sanabel.public.no_donations')" :body="__('sanabel.public.no_donations_help')">
            <a href="{{ route('cases.browse') }}" class="btn-primary mt-2 no-underline">
                {{ __('sanabel.public.browse_cases') }}
            </a>
        </x-empty-state>
    @else
        <div class="card overflow-x-auto !p-0">
            <table class="w-full text-sm">
                <thead style="background-color: var(--page); color: var(--text-muted);">
                    <tr>
                        <th class="px-4 py-3 text-start font-medium">{{ __('sanabel.donation.transaction_ref') }}</th>
                        <th class="px-4 py-3 text-start font-medium">{{ __('sanabel.donation.amount') }}</th>
                        <th class="px-4 py-3 text-start font-medium">{{ __('sanabel.beneficiary.status') }}</th>
                        <th class="px-4 py-3 text-start font-medium">{{ __('sanabel.public.covered_files') }}</th>
                        <th class="px-4 py-3 text-start font-medium">{{ __('sanabel.donation.received_at') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y" style="border-color: var(--border);">
                    @foreach ($donations as $donation)
                        <tr>
                            <td class="tabular px-4 py-3 text-xs" dir="ltr">{{ $donation['transaction_ref'] }}</td>
                            <td class="tabular px-4 py-3 font-medium">{{ number_format($donation['amount']) }}</td>
                            <td class="px-4 py-3">
                                <span @class([
                                    'badge',
                                    'bg-brand-50 text-brand-700 dark:bg-brand-900/50 dark:text-brand-100' => $donation['status'] === 'verified',
                                    'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200' => $donation['status'] === 'rejected',
                                    'bg-gold-100 text-gold-800 dark:bg-gold-900/50 dark:text-gold-100' => $donation['status'] === 'pending',
                                    'bg-slate-200 text-slate-700 dark:bg-slate-700 dark:text-slate-100' => $donation['status'] === 'reversed',
                                ])>{{ $donation['status_label'] }}</span>
                            </td>
                            {{-- File numbers only. Nothing that identifies a family. --}}
                            <td class="tabular px-4 py-3 text-xs" style="color: var(--text-muted);">
                                {{ $donation['cases']->join('، ') ?: '—' }}
                            </td>
                            <td class="tabular px-4 py-3" style="color: var(--text-muted);">{{ $donation['created_at'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
