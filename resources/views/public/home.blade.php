@extends('layouts.app')

@section('content')
    @foreach ($banners as $banner)
        <section class="card mb-6 bg-sanabel-50">
            <h2 class="text-xl">{{ $banner->title_ar }}</h2>
            @if ($banner->body_ar)
                <p class="mt-2 text-slate-700">{{ $banner->body_ar }}</p>
            @endif
            @if ($banner->link)
                <a href="{{ $banner->link }}" class="btn-primary mt-4">{{ __('sanabel.public.more') }}</a>
            @endif
        </section>
    @endforeach

    <section class="mb-10">
        <div class="card bg-white">
            <h1 class="text-2xl">{{ __('sanabel.app_name') }}</h1>
            <p class="mt-3 max-w-2xl text-slate-600">{{ __('sanabel.public.intro') }}</p>
            <div class="mt-5 flex flex-wrap gap-3">
                <a href="{{ route('cases.browse') }}" class="btn-primary">{{ __('sanabel.public.browse_cases') }}</a>
                <a href="{{ route('campaigns.public') }}" class="btn-secondary">{{ __('sanabel.public.campaigns') }}</a>
            </div>
        </div>
    </section>

    @if ($campaigns->isNotEmpty())
        <section class="mb-10">
            <h2 class="mb-4 text-xl">{{ __('sanabel.public.campaigns') }}</h2>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($campaigns as $campaign)
                    <x-campaign-card :campaign="$campaign" />
                @endforeach
            </div>
        </section>
    @endif

    @if ($posts->isNotEmpty())
        <section>
            <h2 class="mb-4 text-xl">{{ __('sanabel.public.news') }}</h2>
            <div class="grid gap-4 sm:grid-cols-3">
                @foreach ($posts as $post)
                    <a href="{{ route('post', $post->slug) }}" class="card block hover:border-sanabel-300">
                        <h3 class="font-bold">{{ $post->title_ar }}</h3>
                        <p class="mt-2 line-clamp-3 text-sm text-slate-600">{{ $post->body_ar }}</p>
                    </a>
                @endforeach
            </div>
        </section>
    @endif
@endsection
