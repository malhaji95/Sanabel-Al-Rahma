@extends('layouts.app')
@section('title', $post->title_ar)

@section('content')
    <article class="card prose max-w-none">
        <h1 class="text-2xl">{{ $post->title_ar }}</h1>
        <div class="mt-4 whitespace-pre-line text-slate-700">{{ $post->body_ar }}</div>
    </article>
@endsection
