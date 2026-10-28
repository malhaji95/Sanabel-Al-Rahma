{{--
    T-179 — the band that keeps «مؤقت» honest.

    The gate in front of the panels can be lifted from the server's
    environment, for a training afternoon or a demonstration. A lock that is
    open and quiet is a lock nobody remembers, so while it is open every panel
    screen says so, in red, above everything else — and names the one line that
    shuts it again, so whoever reads it does not have to come and ask us.

    Drawn for everybody on the panel, not only the four roles the gate is for:
    the person who needs to see this is whoever walks past the screen.
--}}
@if (! \App\Http\Middleware\RequireTwoFactor::enforced())
    {{--
        The colour is written out rather than taken from a Tailwind class: this
        view is rendered inside Filament's own stylesheet, not the site's, and
        a class the panel's build never saw is a band nobody can read. It was
        drawn once in near-black on near-black before this.
    --}}
    <div
        role="alert"
        dir="rtl"
        class="flex flex-wrap items-center justify-center gap-x-2 gap-y-1 px-4 py-2 text-center text-sm font-medium"
        style="background-color: #B91C1C; color: #FFFFFF; position: sticky; top: 0; z-index: 40;"
    >
        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
            <path d="M12 2 1 21h22L12 2Zm0 6 1 7h-2l1-7Zm0 9.5a1.2 1.2 0 1 1 0 2.4 1.2 1.2 0 0 1 0-2.4Z"/>
        </svg>

        <span>{{ __('sanabel.two_factor.off_banner') }}</span>

        <span class="font-normal opacity-90">{{ __('sanabel.two_factor.off_banner_how') }}</span>
    </div>
@endif
