{{--
    T-258 — «تواصل معنا», the three doors their mock-up of 5 November drew:
    open a ticket, follow one, or send a confidential report.

    The words under the desk are rows the association edits (T-122); the
    desk itself is this. The official channels are settings, and one they
    have not adopted is not drawn — «لن نعرض رقمًا افتراضيًا» is theirs.
--}}
<div class="mx-auto max-w-3xl">
    <h1 class="text-xl">{{ __('sanabel.contact.title') }}</h1>
    <p class="mt-2 text-sm leading-relaxed" style="color: var(--text-muted);">
        {{ __('sanabel.contact.lead') }}
    </p>

    {{-- The three doors. --}}
    <div class="mt-5 grid gap-3 sm:grid-cols-3">
        @foreach ([
            'new' => ['sanabel.contact.door_new', 'sanabel.contact.door_new_help'],
            'track' => ['sanabel.contact.door_track', 'sanabel.contact.door_track_help'],
            'secret' => ['sanabel.contact.door_secret', 'sanabel.contact.door_secret_help'],
        ] as $key => [$label, $help])
            <button type="button" wire:click="openDoor('{{ $key }}')"
                    class="card-interactive text-right"
                    @if ($door === $key) style="border-color: var(--accent);" @endif>
                <p class="font-bold" @if ($door === $key) style="color: var(--accent);" @endif>
                    {{ __($label) }}
                </p>
                <p class="mt-1 text-xs" style="color: var(--text-muted);">{{ __($help) }}</p>
            </button>
        @endforeach
    </div>

    @if ($error)
        <p class="mt-4 text-sm text-red-600 dark:text-red-400"
           x-data x-init="$nextTick(() => $el.scrollIntoView({ behavior: 'smooth', block: 'center' }))">{{ $error }}</p>
    @endif

    @if ($door === 'track')
        <div class="card mt-5">
            <h2 class="font-medium">{{ __('sanabel.contact.door_track') }}</h2>

            @if ($tracked)
                <div class="mt-4 space-y-2">
                    <p class="tabular">
                        {{ __('sanabel.complaint.reference_no') }}:
                        <span dir="ltr" class="font-bold">{{ $tracked['reference_no'] }}</span>
                    </p>
                    <p>{{ __('sanabel.contact.subject') }}: {{ $tracked['subject_ar'] }}</p>
                    <p>
                        {{ __('sanabel.beneficiary.status') }}:
                        <span class="badge">{{ $tracked['status_label'] }}</span>
                    </p>
                    <p class="text-xs" style="color: var(--text-muted);">
                        {{ __('sanabel.complaint.created_at') }}:
                        {{ $tracked['created_at']?->format('Y-m-d') }}
                    </p>
                    {{-- Their own note: a public view shows the state and
                         nothing of the desk. --}}
                    <p class="mt-3 text-xs leading-relaxed" style="color: var(--text-muted);">
                        {{ __('sanabel.contact.track_scope') }}
                    </p>
                </div>
            @else
                <form wire:submit="track" class="mt-4 space-y-4">
                    <div>
                        <label class="field-label" for="track-ref">{{ __('sanabel.complaint.reference_no') }}</label>
                        <input id="track-ref" type="text" dir="ltr" wire:model="trackReference"
                               class="field tabular" placeholder="CMP-00-00000">
                    </div>

                    <div>
                        <label class="field-label" for="track-contact">{{ __('sanabel.contact.track_contact') }}</label>
                        <input id="track-contact" type="text" dir="ltr" wire:model="trackContact" class="field tabular">
                    </div>

                    <button type="submit" class="btn-primary w-full justify-center">
                        <span wire:loading.remove wire:target="track">{{ __('sanabel.contact.track_submit') }}</span>
                        <span wire:loading wire:target="track">…</span>
                    </button>
                </form>
            @endif
        </div>
    @else
        @if ($reference)
            <div class="card mt-5 text-center"
                 x-data x-init="$nextTick(() => $el.scrollIntoView({ behavior: 'smooth', block: 'center' }))">
                <p class="font-medium" style="color: var(--accent);">{{ __('sanabel.contact.filed') }}</p>
                <p class="tabular mt-2 text-lg font-bold" dir="ltr">{{ $reference }}</p>
                <p class="mt-2 text-sm" style="color: var(--text-muted);">{{ __('sanabel.contact.keep_reference') }}</p>
                <button type="button" wire:click="openDoor('track')" class="btn-secondary mt-4">
                    {{ __('sanabel.contact.door_track') }}
                </button>
            </div>
        @endif

        <div class="card mt-5">
            @if ($door === 'secret')
                {{-- Their warning, word for word. --}}
                <div class="mb-4 rounded-xl p-3 text-sm leading-relaxed"
                     style="background: var(--surface-muted, rgba(0,0,0,.04));">
                    {{ __('sanabel.contact.secret_notice') }}
                </div>
            @endif

            <form wire:submit="file" class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="field-label" for="c-name">{{ __('sanabel.contact.name') }}</label>
                        <input id="c-name" type="text" wire:model="name" class="field">
                        @error('name') <p class="mt-1.5 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="field-label" for="c-role">{{ __('sanabel.contact.role') }}</label>
                        <select id="c-role" wire:model="role" class="field">
                            <option value="">{{ __('sanabel.contact.choose') }}</option>
                            @foreach ($roles as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('role') <p class="mt-1.5 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="field-label" for="c-phone">{{ __('sanabel.contact.phone') }}</label>
                        <input id="c-phone" type="tel" dir="ltr" wire:model="phone" class="field tabular">
                        @error('phone') <p class="mt-1.5 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="field-label" for="c-email">
                            {{ __('sanabel.contact.email') }}
                            <span class="font-normal" style="color: var(--text-muted);">{{ __('sanabel.optional') }}</span>
                        </label>
                        <input id="c-email" type="email" dir="ltr" wire:model="email" class="field">
                        @error('email') <p class="mt-1.5 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div>
                    <label class="field-label" for="c-kind">{{ __('sanabel.contact.kind') }}</label>
                    <select id="c-kind" wire:model.live="category" class="field">
                        <option value="">{{ __('sanabel.contact.choose') }}</option>
                        @foreach ($categories as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('category') <p class="mt-1.5 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="field-label" for="c-subject">{{ __('sanabel.contact.subject') }}</label>
                    <input id="c-subject" type="text" wire:model="subject" class="field">
                    @error('subject') <p class="mt-1.5 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="field-label" for="c-body">{{ __('sanabel.contact.body') }}</label>
                    <textarea id="c-body" rows="5" wire:model="body" class="field"
                              placeholder="{{ __('sanabel.contact.body_help') }}"></textarea>
                    @error('body') <p class="mt-1.5 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="field-label" for="c-file">
                        {{ __('sanabel.contact.attachment') }}
                        <span class="font-normal" style="color: var(--text-muted);">{{ __('sanabel.optional') }}</span>
                    </label>
                    <input id="c-file" type="file" wire:model="attachment" class="field"
                           accept="image/*,application/pdf">
                    <p class="mt-1 text-xs" wire:loading wire:target="attachment">…</p>
                    @error('attachment') <p class="mt-1.5 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <label class="flex items-start gap-2 text-sm leading-relaxed">
                    <input type="checkbox" wire:model="consent" class="mt-1">
                    <span>
                        {{ __('sanabel.contact.consent_label') }}
                        <a href="{{ url('/privacy') }}" style="color: var(--accent);">{{ __('sanabel.contact.privacy') }}</a>
                    </span>
                </label>
                @error('consent') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror

                <button type="submit" class="btn-primary w-full justify-center">
                    <span wire:loading.remove wire:target="file">{{ __('sanabel.contact.submit') }}</span>
                    <span wire:loading wire:target="file">…</span>
                </button>
            </form>
        </div>
    @endif

    {{-- The association's own channels, each drawn only once adopted. --}}
    <section class="mt-8">
        <h2 class="font-medium">{{ __('sanabel.contact.channels') }}</h2>

        @if ($channels === [])
            <p class="mt-2 text-sm" style="color: var(--text-muted);">{{ __('sanabel.contact.channels_pending') }}</p>
        @else
            <dl class="mt-3 grid gap-3 sm:grid-cols-3">
                @foreach ($channels as $key => $value)
                    <div class="card">
                        <dt class="text-xs" style="color: var(--text-muted);">{{ __('sanabel.settings.keys.'.$key) }}</dt>
                        <dd dir="ltr" class="tabular mt-1 break-words font-medium">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        @endif
    </section>

    {{-- T-122 — whatever the association has written on this page. --}}
    @if ($page?->publishedSections()->exists())
        <section class="mt-8 space-y-3">
            @foreach ($page->publishedSections()->get() as $section)
                @if ($section->kind === 'accordion')
                    <details class="card">
                        <summary class="cursor-pointer font-medium">{{ $section->title_ar }}</summary>
                        <p class="mt-2 text-sm leading-relaxed">{{ $section->body_ar }}</p>
                    </details>
                @elseif (filled($section->title_ar) || filled($section->body_ar))
                    <div class="card">
                        @if (filled($section->title_ar))
                            <p class="font-medium">{{ $section->title_ar }}</p>
                        @endif
                        @if (filled($section->body_ar))
                            <p class="mt-1 text-sm leading-relaxed">{{ $section->body_ar }}</p>
                        @endif
                    </div>
                @endif
            @endforeach
        </section>
    @endif
</div>
