{{-- Light and dark are both first-class; the choice is remembered per device. --}}
<button
    type="button"
    class="btn-ghost !px-2"
    x-data
    @click="
        const dark = document.documentElement.classList.toggle('dark');
        localStorage.setItem('sanabel-theme', dark ? 'dark' : 'light');
    "
    :aria-label="document.documentElement.classList.contains('dark')
        ? '{{ __('sanabel.public.theme_light') }}'
        : '{{ __('sanabel.public.theme_dark') }}'"
    title="{{ __('sanabel.public.theme_toggle') }}"
>
    <svg class="h-5 w-5 dark:hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
        <path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z" stroke-linecap="round" stroke-linejoin="round"/>
    </svg>
    <svg class="hidden h-5 w-5 dark:block" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
        <circle cx="12" cy="12" r="4"/>
        <path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4" stroke-linecap="round"/>
    </svg>
</button>
