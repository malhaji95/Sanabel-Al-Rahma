@extends('layouts.app')
@section('title', __('sanabel.gift.card_heading'))

{{-- The link a recipient opens shows the platform's own name and nothing of
     anybody: no two names in the preview, because a preview is read by
     whoever the message is forwarded to. --}}
@section('share_title', __('sanabel.gift.card_heading'))

@section('content')
    {{--
        T-241 — the card itself.

        What is on it: the association's own design, the two names, and the
        amount only where the giver chose to show it (requirement 6). What is
        never on it: the recipient's number, a family, a file, a figure the
        giver hid.
    --}}
    <article class="mx-auto max-w-xl">
        <div class="overflow-hidden rounded-2xl border" style="border-color: var(--line); background: var(--surface);">
            @if ($gift->card?->imageUrl())
                <img src="{{ $gift->card->imageUrl() }}" alt="{{ $gift->card->name_ar }}"
                     class="h-auto w-full object-cover">
            @endif

            <div class="space-y-4 p-6 text-center">
                <h1 class="text-xl font-bold" style="color: var(--accent);">
                    {{ __('sanabel.gift.card_heading') }}
                </h1>

                <p class="leading-8">
                    {{ __('sanabel.gift.card_line', [
                        'giver' => $gift->giver_name_ar,
                        'recipient' => $gift->recipient_name_ar,
                    ]) }}
                </p>

                @if ($gift->show_amount)
                    <p class="tabular font-bold">
                        {{ __('sanabel.gift.card_amount', ['amount' => number_format($gift->amount)]) }}
                        {{ __('sanabel.currency.'.$gift->currency.'.short') }}
                    </p>
                @endif

                <p class="text-sm" style="color: var(--muted);">
                    @if ($gift->campaign?->is_published)
                        {{ __('sanabel.gift.card_project', ['project' => $gift->campaign->title_ar]) }}
                    @else
                        {{ __('sanabel.gift.card_general') }}
                    @endif
                </p>

                <p class="text-sm">{{ __('sanabel.gift.card_thanks') }}</p>
            </div>
        </div>
    </article>
@endsection
