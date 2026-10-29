{{--
    T-137 — «ضمن قائمة الواجهة للمتبرع او الزائر نعمل القائمة بهالشكل:
    حسابي. سلة التبرع. الزكاة. الهداية. المفضلة», 28 October, and «عند تسجيل
    الدخول سواء لوحة التحكم او الواجهة يظهر الاسم جانب عبارة حسابي».

    Three of the five exist and are here. «الهداية» — a gift given in somebody
    else's name — and «المفضلة» — families kept to come back to — are features
    nobody has built yet, and a heading that leads to a page which cannot do
    what it says is worse than its absence (the same reason the top bar has
    carried no «إهداء» row since T-89). They go in the day the features do.

    A dropdown rather than five more links in the bar: the bar already carries
    the association's own navigation rows, and a phone has no room for both.
    Alpine draws it, and with Alpine unavailable the trigger is still a link to
    the account page, so nothing is unreachable.
--}}
@auth
    <div x-data="{ open: false }" class="relative" @keydown.escape="open = false">
        <button
            type="button"
            class="btn-secondary"
            @click="open = ! open"
            :aria-expanded="open.toString()"
            aria-haspopup="true"
        >
            <span>{{ __('sanabel.public.my_account') }}</span>
            {{-- The name beside the words, which is what the association asked
                 for: a borrowed phone shows whose account is open. --}}
            <span class="hidden font-semibold sm:inline" style="color: var(--accent);">
                — {{ auth()->user()->name }}
            </span>
        </button>

        <div
            x-show="open"
            x-transition.opacity
            @click.outside="open = false"
            class="absolute end-0 z-50 mt-1 w-56 overflow-hidden rounded-xl border shadow-lg"
            style="border-color: var(--border); background-color: var(--page);"
            x-cloak
        >
            <p class="px-4 pt-3 text-xs" style="color: var(--text-muted);">{{ auth()->user()->name }}</p>

            @foreach ([
                ['label' => __('sanabel.public.account_home'), 'href' => route('donor.portal')],
                ['label' => __('sanabel.public.basket'), 'href' => route('donor.basket')],
                ['label' => __('sanabel.favourites.title'), 'href' => route('donor.favourites')],
                ['label' => __('sanabel.public.zakat_due'), 'href' => route('cases.browse', ['zakat' => 1])],
            ] as $item)
                <a href="{{ $item['href'] }}" class="block px-4 py-2.5 text-sm no-underline hover:opacity-80">
                    {{ $item['label'] }}
                </a>
            @endforeach

            <form method="POST" action="{{ route('logout') }}" class="border-t" style="border-color: var(--border);">
                @csrf
                <button type="submit" class="w-full px-4 py-2.5 text-start text-sm">
                    {{ __('sanabel.public.logout_full') }}
                </button>
            </form>
        </div>
    </div>
@else
    <a href="{{ route('login') }}" class="btn-primary no-underline">
        {{ __('sanabel.public.login') }}
    </a>
@endauth
