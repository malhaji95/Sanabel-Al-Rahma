@extends('layouts.app')
@section('title', $post->title_ar)

@section('content')
    @if ($isPreview ?? false)
        {{-- Only an editor sees this. Switching the width shows the same page as
             a phone renders it, so the piece is checked before it goes out. The
             bar and the frame share one Alpine scope, so the toggle reaches it. --}}
        <div x-data="{ narrow: false }">
        <div class="card mx-auto mb-6 flex max-w-3xl flex-wrap items-center justify-between gap-3">
            <p class="text-sm font-medium">
                {{ __('sanabel.post.preview_notice') }}
                <span class="badge bg-gold-100 text-gold-800 dark:bg-gold-900/50 dark:text-gold-100">
                    {{ __('sanabel.post.statuses.'.$post->status) }}
                </span>
            </p>

            <div class="flex gap-2">
                <button type="button" class="btn-secondary" x-on:click="narrow = false" :aria-pressed="!narrow">
                    {{ __('sanabel.post.preview_desktop') }}
                </button>
                <button type="button" class="btn-secondary" x-on:click="narrow = true" :aria-pressed="narrow">
                    {{ __('sanabel.post.preview_phone') }}
                </button>
            </div>
        </div>

        <div class="mx-auto transition-all" :class="narrow ? 'max-w-sm' : ''">
    @endif

    <article class="card mx-auto max-w-3xl">
        <h1 class="text-2xl leading-snug">{{ $post->title_ar }}</h1>

        @if ($post->published_at)
            <p class="tabular mt-2 text-sm" style="color: var(--text-muted);">
                {{ __('sanabel.public.published_on') }} {{ $post->published_at->translatedFormat('Y-m-d') }}
            </p>
        @endif

        <div class="rule-gold my-6"></div>

        @if ($post->imageUrl())
            <img src="{{ $post->imageUrl() }}" alt="{{ $post->title_ar }}"
                 class="mb-6 w-full rounded-xl object-cover">
        @endif

        {{-- Rich text. The model strips anything outside the editor toolbar
             before saving, so no markup reaches here that it did not allow. --}}
        <div class="prose-news leading-loose">{!! $post->body_ar !!}</div>
    </article>

    <div class="mx-auto mt-6 max-w-3xl">
        <a href="{{ route('news') }}" class="btn-secondary no-underline">{{ __('sanabel.public.news') }}</a>
    </div>

    @if ($isPreview ?? false)
        </div>
        </div>
    @endif
@endsection
