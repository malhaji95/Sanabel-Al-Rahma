{{--
    T-89 — a row of ways to give. Each is a content card: a picture or an icon,
    a name, and the list it opens. A card with no artwork yet draws its icon
    rather than an empty frame.
--}}
@props(['tiles'])

@if ($tiles->isNotEmpty())
    <div {{ $attributes->merge(['class' => 'grid gap-4 sm:grid-cols-2 lg:grid-cols-4']) }}>
        @foreach ($tiles as $tile)
            <article class="card-interactive flex flex-col overflow-hidden !p-0">
                @if ($tile->imageUrl())
                    <img src="{{ $tile->imageUrl() }}" alt="" class="h-32 w-full object-cover" loading="lazy">
                @else
                    {{-- T-104 — the frame a card draws until the association
                         uploads its picture. It used to be a 12% tint, which
                         on the dark scheme is very nearly the card colour: the
                         association read five of these as pictures that had
                         failed to load. It is a deliberate panel now, and
                         `launch:check` lists which cards are still waiting on
                         artwork. --}}
                    <div class="flex h-32 w-full items-center justify-center"
                         style="background: linear-gradient(135deg, color-mix(in srgb, var(--accent) 38%, var(--card)), color-mix(in srgb, var(--brand-gold) 16%, var(--page))); color: var(--accent);">
                        <x-brand.icon :name="$tile->icon ?: 'heart'" class="h-14 w-14 opacity-90" />
                    </div>
                @endif

                <div class="flex flex-1 flex-col gap-2 p-4">
                    {{-- The icon sits beside the name only when a picture
                         filled the frame above; without one it is already
                         drawn there, and twice is once too many. --}}
                    <h3 class="inline-flex items-center gap-2 text-base">
                        @if ($tile->icon && $tile->imageUrl())
                            <x-brand.icon :name="$tile->icon" class="h-5 w-5" style="color: var(--accent);" />
                        @endif
                        {{ $tile->title_ar }}
                    </h3>

                    @if ($tile->body_ar)
                        <p class="text-sm leading-relaxed" style="color: var(--text-muted);">{{ $tile->body_ar }}</p>
                    @endif

                    @if ($tile->link)
                        <a href="{{ $tile->link }}" class="btn-secondary mt-auto w-full justify-center no-underline">
                            {{ __('sanabel.public.donate_now') }}
                        </a>
                    @endif
                </div>
            </article>
        @endforeach
    </div>
@endif
