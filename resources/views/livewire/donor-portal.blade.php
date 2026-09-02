<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <h1 class="text-2xl">{{ __('sanabel.public.my_donations') }}</h1>

        @if ($donor)
            <div class="flex items-center gap-3 text-sm">
                <span class="text-slate-500">{{ __('sanabel.public.badge') }}</span>
                <span @class([
                    'badge',
                    'bg-yellow-100 text-yellow-800' => $donor->badge === 'gold',
                    'bg-slate-200 text-slate-700' => $donor->badge === 'silver',
                    'bg-slate-100 text-slate-500' => $donor->badge === 'none',
                ])>{{ __('sanabel.badge.' . $donor->badge) }}</span>
                <span class="text-slate-500">
                    {{ __('sanabel.public.verified_donations', ['count' => $donor->donations_count]) }}
                </span>
            </div>
        @endif
    </div>

    <div class="flex gap-3">
        <a href="{{ route('cases.browse') }}" class="btn-primary">{{ __('sanabel.public.cases') }}</a>
        <a href="{{ route('donor.basket') }}" class="btn-secondary">{{ __('sanabel.public.basket') }}</a>
    </div>

    @if ($donations->isEmpty())
        <div class="card text-center text-slate-500">{{ __('sanabel.public.no_donations') }}</div>
    @else
        <div class="card overflow-x-auto p-0">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-slate-600">
                    <tr>
                        <th class="px-4 py-3 text-start">{{ __('sanabel.donation.transaction_ref') }}</th>
                        <th class="px-4 py-3 text-start">{{ __('sanabel.donation.amount') }}</th>
                        <th class="px-4 py-3 text-start">{{ __('sanabel.beneficiary.status') }}</th>
                        <th class="px-4 py-3 text-start">{{ __('sanabel.public.covered_files') }}</th>
                        <th class="px-4 py-3 text-start">{{ __('sanabel.donation.received_at') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($donations as $donation)
                        <tr>
                            <td class="px-4 py-3 font-mono text-xs">{{ $donation['transaction_ref'] }}</td>
                            <td class="px-4 py-3">{{ number_format($donation['amount']) }}</td>
                            <td class="px-4 py-3">
                                <span @class([
                                    'badge',
                                    'bg-green-100 text-green-800' => $donation['status'] === 'verified',
                                    'bg-red-100 text-red-800' => $donation['status'] === 'rejected',
                                    'bg-amber-100 text-amber-800' => $donation['status'] === 'pending',
                                    'bg-slate-100 text-slate-700' => $donation['status'] === 'reversed',
                                ])>{{ $donation['status_label'] }}</span>
                            </td>
                            {{-- File numbers only. Nothing that identifies a family. --}}
                            <td class="px-4 py-3 text-xs">{{ $donation['cases']->join('، ') ?: '—' }}</td>
                            <td class="px-4 py-3">{{ $donation['created_at'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
