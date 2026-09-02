{{-- An empty list should still feel like part of the platform, not a dead end. --}}
@props(['title', 'body' => null])

<div class="card flex flex-col items-center gap-3 py-14 text-center">
    <x-brand.logo variant="symbol" :height="56" class="opacity-25" />

    <p class="font-medium">{{ $title }}</p>

    @if ($body)
        <p class="max-w-sm text-sm" style="color: var(--text-muted);">{{ $body }}</p>
    @endif

    {{ $slot }}
</div>
