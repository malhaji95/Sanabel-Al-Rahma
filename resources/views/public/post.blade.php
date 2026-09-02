@extends('layouts.app')
@section('title', $post->title_ar)

@section('content')
    <article class="card mx-auto max-w-3xl">
        <h1 class="text-2xl leading-snug">{{ $post->title_ar }}</h1>

        @if ($post->published_at)
            <p class="tabular mt-2 text-sm" style="color: var(--text-muted);">
                {{ __('sanabel.public.published_on') }} {{ $post->published_at->translatedFormat('Y-m-d') }}
            </p>
        @endif

        <div class="rule-gold my-6"></div>

        <div class="whitespace-pre-line leading-loose">{{ $post->body_ar }}</div>
    </article>

    <div class="mx-auto mt-6 max-w-3xl">
        <a href="{{ route('news') }}" class="btn-secondary no-underline">{{ __('sanabel.public.news') }}</a>
    </div>
@endsection
