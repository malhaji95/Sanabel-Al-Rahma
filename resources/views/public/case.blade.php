@extends('layouts.app')

@section('title', $card ? __('sanabel.public.case_title', ['file' => $card['file_number']]) : __('sanabel.share.closed_title'))

{{-- What a shared link shows before anyone opens it. Deliberately general: the
     kind of help and the area, never a household's own particulars. --}}
@section('share_title', $card
    ? __('sanabel.share.case_title', ['need' => $card['need_type_label'], 'area' => $card['area_ar']])
    : __('sanabel.share.closed_title'))
@section('share_description', __('sanabel.share.case_description'))

@section('content')
    @if ($closed)
        <div class="card mx-auto max-w-2xl text-center">
            <h1 class="text-xl">{{ __('sanabel.share.closed_title') }}</h1>
            <p class="mt-3" style="color: var(--text-muted);">{{ __('sanabel.share.closed_body') }}</p>
            <a href="{{ route('cases.browse') }}" class="btn-primary mt-5 inline-block no-underline">
                {{ __('sanabel.share.browse_others') }}
            </a>
        </div>

        @if ($alternatives->isNotEmpty())
            <h2 class="mb-4 mt-10 text-lg">{{ __('sanabel.share.other_cases') }}</h2>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($alternatives as $other)
                    <x-masked-case-card :case="$other" />
                @endforeach
            </div>
        @endif
    @else
        <div class="mx-auto max-w-2xl">
            <x-masked-case-card :case="$card" />

            <div class="mt-5 flex flex-wrap items-center gap-3">
                <a href="{{ route('donor.basket') }}" class="btn-primary no-underline">
                    {{ __('sanabel.public.donate_now') }}
                </a>

                @if ($card['can_share'] ?? true)
                    <x-share-button
                        :url="route('opportunity', $card['file_number'])"
                        :title="__('sanabel.share.case_title', ['need' => $card['need_type_label'], 'area' => $card['area_ar']])" />
                @endif
            </div>
        </div>
    @endif
@endsection
