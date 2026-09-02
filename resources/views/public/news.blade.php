@extends('layouts.app')
@section('title', __('sanabel.public.news'))

@section('content')
    <h1 class="mb-6 text-2xl">{{ __('sanabel.public.news') }}</h1>

    @if ($posts->isEmpty())
        <x-empty-state :title="__('sanabel.public.no_news')" />
    @else
        <div class="grid gap-4 sm:grid-cols-2">
            @foreach ($posts as $post)
                <a href="{{ route('post', $post->slug) }}" class="card-interactive block no-underline">
                    <h2 class="text-base">{{ $post->title_ar }}</h2>
                    @if ($post->published_at)
                        <p class="tabular mt-1 text-xs" style="color: var(--text-muted);">
                            {{ __('sanabel.public.published_on') }} {{ $post->published_at->translatedFormat('Y-m-d') }}
                        </p>
                    @endif
                    <p class="mt-2 line-clamp-3 text-sm leading-relaxed" style="color: var(--text-muted);">
                        {{ $post->body_ar }}
                    </p>
                    <span class="mt-3 inline-block text-sm font-medium" style="color: var(--accent);">
                        {{ __('sanabel.public.read_more') }}
                    </span>
                </a>
            @endforeach
        </div>

        <div class="mt-8">{{ $posts->links() }}</div>
    @endif
@endsection
