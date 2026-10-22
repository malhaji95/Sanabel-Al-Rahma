@extends('layouts.app')
@section('title', $campaign->title_ar)

@section('content')
    {{--
        T-117 — a campaign on its own page, so the association can tell the
        story behind it and a donor can read past the third line.

        It is the same card the list draws, with the clamp off: there is one
        place where a campaign's goal, what it has collected and what is left
        are worked out, and a second one would eventually disagree with it.
    --}}
    <article class="mx-auto max-w-2xl">
        <nav class="mb-4 text-sm">
            <a href="{{ route('campaigns.public') }}" style="color: var(--accent);">
                {{ __('sanabel.public.back_to_campaigns') }}
            </a>
        </nav>

        <x-campaign-card :campaign="$campaign" :monthly="$monthly" :full="true" />
    </article>
@endsection
