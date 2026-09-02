@extends('layouts.app')
@section('title', $page->title_ar)

@section('content')
    <article class="card">
        <h1 class="text-2xl">{{ $page->title_ar }}</h1>
        <div class="mt-4 whitespace-pre-line text-slate-700">{{ $page->body_ar }}</div>
    </article>
@endsection
