@extends('layouts.app')
@section('title', $page->title_ar)

@section('content')
    {{--
        T-113 — a page as the association designed it on 19 October: a quick
        summary, details that open and close, and links to the policies.

        Every piece is a row they edit. Nothing here is specific to «كيف
        نعمل» — the same nine shapes serve the other six pages, which is what
        they asked for next.

        It reads without JavaScript. The panels are <details>, the strip is a
        column of links, and the one piece that needs a script (the funding
        example) shows its figures before any script runs.
    --}}
    <article class="mx-auto max-w-3xl">
        <header class="card">
            <h1 class="text-2xl leading-snug">{{ $page->title_ar }}</h1>

            <div class="rule-gold my-6"></div>

            @if (filled($page->body_ar))
                <div class="whitespace-pre-line leading-loose">{{ $page->body_ar }}</div>
            @endif

            {{-- The three levels as a way in, drawn from the headings
                 themselves so the page cannot promise a level it has lost. --}}
            @php($levels = $sections->where('kind', 'heading')->filter->anchorId())

            @if ($levels->count() > 1)
                <nav class="mt-5 flex flex-wrap gap-2" aria-label="{{ __('sanabel.page.levels') }}">
                    @foreach ($levels as $level)
                        <a href="#{{ $level->anchorId() }}"
                           class="btn-secondary inline-flex items-center gap-1 text-sm no-underline">
                            {{ $level->badge_ar ?: $level->title_ar }}
                        </a>
                    @endforeach
                </nav>
            @endif
        </header>

        @foreach ($sections as $section)
            @php($anchor = $section->anchorId())

            @switch($section->kind)
                @case('heading')
                    <section @if ($anchor) id="{{ $anchor }}" @endif class="mt-10 scroll-mt-24">
                        @if ($section->badge_ar)
                            <span class="badge bg-gold-100 text-gold-800 dark:bg-gold-900/50 dark:text-gold-100">
                                {{ $section->badge_ar }}
                            </span>
                        @endif

                        @if ($section->title_ar)
                            <h2 class="mt-2 text-xl leading-snug">{{ $section->title_ar }}</h2>
                        @endif

                        @if ($section->body_ar)
                            <p class="mt-2 whitespace-pre-line leading-loose" style="color: var(--text-muted);">
                                {{ $section->body_ar }}
                            </p>
                        @endif
                    </section>
                    @break

                @case('group')
                    <h3 @if ($anchor) id="{{ $anchor }}" @endif class="mt-8 scroll-mt-24 text-lg">
                        @if ($section->badge_ar)
                            <span class="badge me-2" style="background-color: var(--page); color: var(--accent);">
                                {{ $section->badge_ar }}
                            </span>
                        @endif
                        {{ $section->title_ar }}
                    </h3>
                    @break

                @case('card')
                    <div class="card mt-3 scroll-mt-24" @if ($anchor) id="{{ $anchor }}" @endif>
                        <h3 class="inline-flex items-center gap-2 text-base">
                            @if ($section->badge_ar)
                                <span class="grid h-8 w-8 shrink-0 place-items-center rounded-xl text-sm font-bold"
                                      style="background-color: var(--page); color: var(--accent);">
                                    {{ $section->badge_ar }}
                                </span>
                            @endif
                            {{ $section->title_ar }}
                        </h3>

                        @if ($section->body_ar)
                            <p class="mt-2 whitespace-pre-line text-sm leading-loose" style="color: var(--text-muted);">
                                {{ $section->body_ar }}
                            </p>
                        @endif
                    </div>
                    @break

                @case('accordion')
                    {{-- A disclosure element, so it opens with the script
                         switched off and a browser can find text inside it. --}}
                    <details @if ($anchor) id="{{ $anchor }}" @endif class="card mt-3 scroll-mt-24 !p-0">
                        <summary class="cursor-pointer list-none px-5 py-4 font-bold">
                            {{ $section->title_ar }}
                        </summary>

                        <div class="border-t px-5 py-4" style="border-color: var(--border);">
                            @if ($section->body_ar)
                                <div class="whitespace-pre-line text-sm leading-loose">{{ $section->body_ar }}</div>
                            @endif

                            @if ($section->rule_ar)
                                <p class="mt-3 rounded-lg border-s-4 p-3 text-sm font-medium"
                                   style="border-color: var(--accent); background-color: var(--page);">
                                    {{ $section->rule_ar }}
                                </p>
                            @endif

                            @if ($section->link)
                                <a href="{{ $section->link }}" class="mt-3 inline-block text-sm" style="color: var(--accent);">
                                    {{ $section->link_label_ar ?: __('sanabel.page.read_more') }}
                                </a>
                            @endif
                        </div>
                    </details>
                    @break

                @case('reference')
                    <a href="{{ $section->link ?: '#' }}"
                       @if ($anchor) id="{{ $anchor }}" @endif
                       class="card-interactive mt-3 block scroll-mt-24 no-underline">
                        <p class="font-bold" style="color: var(--accent);">{{ $section->title_ar }}</p>

                        @if ($section->badge_ar)
                            <span class="badge mt-1.5 bg-gold-100 text-gold-800 dark:bg-gold-900/50 dark:text-gold-100">
                                {{ $section->badge_ar }}
                            </span>
                        @endif

                        @if ($section->body_ar)
                            <p class="mt-1.5 text-sm" style="color: var(--text-muted);">{{ $section->body_ar }}</p>
                        @endif
                    </a>
                    @break

                @case('document')
                    <section @if ($anchor) id="{{ $anchor }}" @endif class="card mt-4 scroll-mt-24">
                        <h3 class="text-base">{{ $section->title_ar }}</h3>

                        @if ($section->body_ar)
                            <div class="mt-2 whitespace-pre-line text-sm leading-loose" style="color: var(--text-muted);">
                                {{ $section->body_ar }}
                            </div>
                        @endif

                        @if ($section->link)
                            <a href="{{ $section->link }}" class="mt-3 inline-block text-sm" style="color: var(--accent);">
                                {{ $section->link_label_ar ?: __('sanabel.page.read_more') }}
                            </a>
                        @endif
                    </section>
                    @break

                @case('statement')
                    <blockquote @if ($anchor) id="{{ $anchor }}" @endif
                                class="mt-6 scroll-mt-24 rounded-2xl p-6 text-lg font-bold leading-loose"
                                style="background-color: var(--accent); color: #fff;">
                        {{ $section->body_ar ?: $section->title_ar }}
                    </blockquote>
                    @break

                @case('note')
                    <aside @if ($anchor) id="{{ $anchor }}" @endif
                           class="mt-4 scroll-mt-24 rounded-xl border p-4 text-sm leading-loose"
                           style="border-color: var(--border); background-color: var(--page);">
                        @if ($section->title_ar)
                            <strong>{{ $section->title_ar }}</strong>
                        @endif
                        <span class="whitespace-pre-line">{{ $section->body_ar }}</span>
                    </aside>
                    @break

                @case('funding')
                    <x-page.funding-example :section="$section" />
                    @break
            @endswitch
        @endforeach
    </article>
@endsection
