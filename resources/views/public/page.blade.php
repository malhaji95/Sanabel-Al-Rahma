@extends('layouts.app')
@section('title', $page->title_ar)

@section('content')
    <article class="card mx-auto max-w-3xl">
        <h1 class="text-2xl leading-snug">{{ $page->title_ar }}</h1>

        <div class="rule-gold my-6"></div>

        <div class="whitespace-pre-line leading-loose">{{ $page->body_ar }}</div>
    </article>
@endsection
