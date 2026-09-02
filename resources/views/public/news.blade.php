@extends('layouts.app')
@section('title', __('sanabel.public.news'))

@section('content')
    <h1 class="mb-6 text-2xl">{{ __('sanabel.public.news') }}</h1>

    <div class="space-y-4">
        @forelse ($posts as $post)
            <a href="{{ route('post', $post->slug) }}" class="card block hover:border-sanabel-300">
                <h2 class="font-bold">{{ $post->title_ar }}</h2>
                <p class="mt-2 line-clamp-2 text-sm text-slate-600">{{ $post->body_ar }}</p>
            </a>
        @empty
            <div class="card text-center text-slate-500">{{ __('sanabel.public.no_news') }}</div>
        @endforelse
    </div>

    <div class="mt-6">{{ $posts->links() }}</div>
@endsection
