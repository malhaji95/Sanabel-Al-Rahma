@extends('layouts.app')
@section('title', __('sanabel.public.login'))

@section('content')
    <div class="mx-auto max-w-md">
        <form method="POST" action="{{ route('login') }}" class="card space-y-4">
            @csrf

            <h1 class="text-xl">{{ __('sanabel.public.login') }}</h1>

            <label class="block text-sm">
                {{ __('sanabel.user.email') }}
                <input type="email" name="email" value="{{ old('email') }}" class="field mt-1" required autofocus>
            </label>

            <label class="block text-sm">
                {{ __('sanabel.user.password') }}
                <input type="password" name="password" class="field mt-1" required>
            </label>

            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="remember" class="rounded border-slate-300">
                {{ __('sanabel.auth.remember') }}
            </label>

            @error('email')
                <p class="text-sm text-red-600">{{ $message }}</p>
            @enderror

            <button type="submit" class="btn-primary w-full">{{ __('sanabel.public.login') }}</button>
        </form>
    </div>
@endsection
