@extends('layouts.app')
@section('title', __('sanabel.public.campaigns'))

@section('content')
    <h1 class="mb-6 text-2xl">{{ __('sanabel.public.campaigns') }}</h1>

    @if ($campaigns->isEmpty())
        <div class="card text-center text-slate-500">{{ __('sanabel.public.no_campaigns') }}</div>
    @else
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($campaigns as $campaign)
                <x-campaign-card :campaign="$campaign" />
            @endforeach
        </div>

        <div class="mt-6">{{ $campaigns->links() }}</div>
    @endif
@endsection
