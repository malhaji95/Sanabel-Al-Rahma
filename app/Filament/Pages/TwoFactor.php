<?php

namespace App\Filament\Pages;

use App\Http\Middleware\RequireTwoFactor;
use App\Services\QrCode;
use App\Services\Totp;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Enrolment and challenge in one screen: a user without a secret is shown one
 * to enrol; a user who has one is asked for the current code.
 */
class TwoFactor extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static bool $shouldRegisterNavigation = false;

    /** T-276 — wrong codes allowed before the screen stops answering. */
    private const MAX_ATTEMPTS = 5;

    /** And for how long, in seconds. */
    private const ATTEMPT_WINDOW = 900;

    protected static string $view = 'filament.pages.two-factor';

    protected static ?string $slug = 'two-factor';

    public ?array $data = [];

    public ?string $pendingSecret = null;

    public static function getNavigationLabel(): string
    {
        return __('sanabel.two_factor.title');
    }

    public function getTitle(): string
    {
        return __('sanabel.two_factor.title');
    }

    public function mount(): void
    {
        $user = auth()->user();

        abort_unless($user?->requiresTwoFactor(), 403);

        if (! $user->hasConfirmedTwoFactor()) {
            // Held in the session until the first correct code proves it was stored.
            $this->pendingSecret = session()->get('sanabel.pending_2fa_secret')
                ?? tap(app(Totp::class)->generateSecret(), fn ($s) => session()->put('sanabel.pending_2fa_secret', $s));
        }
    }

    public function form(Forms\Form $form): Forms\Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('code')
                    ->label(__('sanabel.two_factor.code'))
                    ->required()
                    ->numeric()
                    ->length(6),
            ])
            ->statePath('data');
    }

    public function confirm(): void
    {
        $user = auth()->user();
        $totp = app(Totp::class);
        $code = (string) $this->form->getState()['code'];

        /*
         | T-276 — a limit of its own on guessing the code.
         |
         | This screen had none. It is reached through `/livewire/update`,
         | which allows three hundred a minute — and three of the million
         | six-digit codes are valid at any moment, because the window is
         | one step either side. That is better than four hundred thousand
         | tries a day against an account whose password is already known,
         | and a six-digit secret does not survive that for long.
         |
         | Keyed on the account, and the account is known here: the password
         | has already been accepted and only the second factor is left.
         */
        $key = 'two-factor:'.$user->getKey();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            Notification::make()
                ->title(__('sanabel.two_factor.too_many', [
                    'minutes' => (int) ceil(RateLimiter::availableIn($key) / 60),
                ]))
                ->danger()
                ->send();

            return;
        }

        $secret = $this->pendingSecret ?? $user->two_factor_secret;
        $counter = $secret ? $totp->matchingCounter($secret, $code) : null;

        if ($counter === null) {
            RateLimiter::hit($key, self::ATTEMPT_WINDOW);

            Notification::make()->title(__('sanabel.two_factor.invalid'))->danger()->send();

            return;
        }

        /*
         | T-276 — and a step is accepted once (RFC 6238 §5.2). A code is
         | good for ninety seconds, and within them the same six digits
         | worked again and again: one read over a shoulder, or caught by a
         | page standing between the user and this one, could be spent a
         | second time by whoever caught it.
         */
        if ($user->two_factor_last_counter !== null && $counter <= (int) $user->two_factor_last_counter) {
            RateLimiter::hit($key, self::ATTEMPT_WINDOW);

            Notification::make()->title(__('sanabel.two_factor.already_used'))->danger()->send();

            return;
        }

        RateLimiter::clear($key);

        if ($this->pendingSecret) {
            $user->forceFill([
                'two_factor_secret' => $this->pendingSecret,
                'two_factor_confirmed_at' => now(),
            ])->save();

            session()->forget('sanabel.pending_2fa_secret');
            $this->pendingSecret = null;
        }

        $user->forceFill(['two_factor_last_counter' => $counter])->save();

        session()->put(RequireTwoFactor::SESSION_KEY, now()->toIso8601String());

        $this->redirect(filament()->getCurrentPanel()->getUrl());
    }

    public function provisioningUri(): ?string
    {
        if (! $this->pendingSecret) {
            return null;
        }

        return app(Totp::class)->provisioningUri(
            $this->pendingSecret,
            auth()->user()->email,
            (string) config('app.name'),
        );
    }

    /**
     * T-119 — the same URI as a code to photograph.
     *
     * Drawn here rather than fetched: the URI carries the second factor's
     * secret, so it is never handed to anything outside this application,
     * and the page it is drawn on is already behind the login.
     */
    public function qrSvg(): ?string
    {
        $uri = $this->provisioningUri();

        return $uri ? app(QrCode::class)->svg($uri, moduleSize: 5) : null;
    }
}
