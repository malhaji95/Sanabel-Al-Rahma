<?php

namespace App\Filament\Pages;

use App\Http\Middleware\RequireTwoFactor;
use App\Services\Totp;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Enrolment and challenge in one screen: a user without a secret is shown one
 * to enrol; a user who has one is asked for the current code.
 */
class TwoFactor extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static bool $shouldRegisterNavigation = false;

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

        $secret = $this->pendingSecret ?? $user->two_factor_secret;

        if (! $secret || ! $totp->verify($secret, $code)) {
            Notification::make()->title(__('sanabel.two_factor.invalid'))->danger()->send();

            return;
        }

        if ($this->pendingSecret) {
            $user->forceFill([
                'two_factor_secret' => $this->pendingSecret,
                'two_factor_confirmed_at' => now(),
            ])->save();

            session()->forget('sanabel.pending_2fa_secret');
            $this->pendingSecret = null;
        }

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
}
