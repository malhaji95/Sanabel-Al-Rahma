@extends('layouts.app')
@section('title', __('sanabel.public.login'))

@section('content')
    <div class="mx-auto max-w-md">
        <div class="mb-6 flex justify-center">
            <x-brand.logo-plate variant="full" :height="140" />
        </div>

        <form method="POST" action="{{ route('login') }}" class="card space-y-5">
            @csrf

            <h1 class="text-xl">{{ __('sanabel.public.login') }}</h1>

            <div>
                <label class="field-label" for="email">{{ __('sanabel.user.email') }}</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}"
                       class="field" dir="ltr" required autofocus autocomplete="email">
            </div>

            <div>
                <label class="field-label" for="password">{{ __('sanabel.user.password') }}</label>
                <input id="password" type="password" name="password" class="field"
                       required autocomplete="current-password">
            </div>

            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="remember" class="rounded" style="accent-color: var(--accent);">
                {{ __('sanabel.auth.remember') }}
            </label>

            @error('email')
                <div class="alert-danger" role="alert">{{ $message }}</div>
            @enderror

            <button type="submit" class="btn-primary w-full">{{ __('sanabel.public.login') }}</button>
        </form>
    </div>
@endsection
