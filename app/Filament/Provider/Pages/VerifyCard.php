<?php

namespace App\Filament\Provider\Pages;

use App\Http\Resources\ReferralCardResource;
use App\Models\Referral;
use App\Services\ReferralService;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * T-30 — hard rule 4. A provider sees only the referral presented to it:
 * file number, validity, discount type. Nothing about the family.
 */
class VerifyCard extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-qr-code';

    protected static string $view = 'filament.provider.pages.verify-card';

    public ?array $data = [];

    /** @var array<string,mixed>|null */
    public ?array $card = null;

    public ?string $code = null;

    public static function getNavigationLabel(): string
    {
        return __('sanabel.referral.verify');
    }

    public function getTitle(): string
    {
        return __('sanabel.referral.verify');
    }

    public function form(Forms\Form $form): Forms\Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('code')
                    ->label(__('sanabel.referral.code'))
                    ->required()
                    ->maxLength(32),
            ])
            ->statePath('data');
    }

    public function verify(): void
    {
        $referral = $this->findOwnReferral($this->form->getState()['code']);

        if (! $referral) {
            $this->card = null;
            Notification::make()->title(__('sanabel.referral.not_found'))->danger()->send();

            return;
        }

        $this->code = $referral->code;
        $this->card = (new ReferralCardResource($referral))->resolve();
    }

    public function redeem(): void
    {
        $referral = $this->findOwnReferral((string) $this->code);

        if (! $referral) {
            return;
        }

        try {
            // proof_media_id 0 records a counter redemption with no uploaded document yet.
            $referral = app(ReferralService::class)->redeem($referral, 0);
        } catch (\RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        $this->card = (new ReferralCardResource($referral))->resolve();

        Notification::make()->title(__('sanabel.referral.redeemed'))->success()->send();
    }

    /** A provider can only ever look at a card issued to itself. */
    private function findOwnReferral(string $code): ?Referral
    {
        $provider = auth()->user()->provider;

        if (! $provider) {
            return null;
        }

        return Referral::with('beneficiary', 'provider')
            ->where('code', $code)
            ->where('provider_id', $provider->getKey())
            ->first();
    }
}
