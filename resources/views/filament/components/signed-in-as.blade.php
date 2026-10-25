{{--
    T-137 — «عند تسجيل الدخول سواء لوحة التحكم او الواجهة يظهر الاسم جانب
    عبارة حسابي, اسم الحساب اقصد», 28 October.

    Filament draws an avatar in the bar and keeps the name inside the menu, so
    the name is one click away on a screen that is open all day — and on a
    shared desk, whose account is open is exactly what somebody needs to read
    without clicking anything. The role is beside it for the same reason: half
    the questions the association asks us start with «بأي حساب كنت داخلًا».
--}}
@php($user = auth()->user())

@if ($user)
    <div class="me-1 hidden flex-col items-end leading-tight md:flex">
        <span class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ $user->name }}</span>

        @if ($user->role)
            <span class="text-xs text-gray-500 dark:text-gray-400">{{ $user->role->name_ar }}</span>
        @endif
    </div>
@endif
