<?php

namespace App\Filament\Resources\BeneficiaryResource\Pages;

use App\Filament\Resources\BeneficiaryResource;
use App\Models\Beneficiary;
use App\Services\AnonymiseService;
use App\Services\AssessmentService;
use App\Services\CaseService;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditBeneficiary extends EditRecord
{
    protected static string $resource = BeneficiaryResource::class;

    /**
     * `$hidden` keeps the encrypted columns out of `attributesToArray()`, which
     * is what Filament fills the form from — so the phone and wallet of an
     * existing family arrived at the screen empty, and saving wrote the blanks
     * back over them. They are put back here, by name, so nothing else leaks.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        foreach (['phone_encrypted', 'wallet_encrypted', 'national_id_encrypted'] as $column) {
            $data[$column] = $this->record->{$column};
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (isset($data['national_id_encrypted'])) {
            $data['national_id_hash'] = Beneficiary::hashNationalId((string) $data['national_id_encrypted']);
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('assess')
                ->label(__('sanabel.actions.recompute_assessment'))
                ->icon('heroicon-o-calculator')
                ->requiresConfirmation()
                ->action(function () {
                    $assessment = app(AssessmentService::class)->create($this->record, status: 'approved');

                    Notification::make()
                        ->title(__('sanabel.actions.assessment_done'))
                        ->body(__('sanabel.beneficiary.computed_need').': '.$assessment->monthly_need)
                        ->success()->send();
                }),

            /*
             | T-59 (PAY-05) — where the family's money goes.
             |
             | On a live file the wallet field is read-only, and this is the
             | only way to change it: the request is raised here and somebody
             | other than whoever raised it approves it on the change-request
             | screen. Offered only to the roles that may ask for a change.
             */
            Actions\Action::make('requestPaymentChange')
                ->label(__('sanabel.cases.request_payment_change'))
                ->icon('heroicon-o-banknotes')
                ->color('warning')
                ->visible(fn () => BeneficiaryResource::paymentDetailsLocked($this->record)
                    && auth()->user()->can_('request_change'))
                ->modalDescription(__('sanabel.cases.request_payment_change_help'))
                ->form([
                    Forms\Components\TextInput::make('wallet_encrypted')
                        ->label(__('sanabel.beneficiary.wallet'))
                        ->required(),
                    Forms\Components\Textarea::make('reason_ar')
                        ->label(__('sanabel.actions.reason'))
                        ->required(),
                ])
                ->action(function (array $data) {
                    try {
                        app(CaseService::class)->requestChange(
                            $this->record,
                            auth()->user(),
                            ['wallet_encrypted' => $data['wallet_encrypted']],
                            $data['reason_ar'],
                        );
                    } catch (\RuntimeException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()->title(__('sanabel.cases.payment_change_requested'))->success()->send();
                }),

            // The two field steps sit before the admin's button, in the order
            // they must happen; each disappears once it has been taken.
            Actions\Action::make('verifyInField')
                ->label(__('sanabel.actions.verify_in_field'))
                ->icon('heroicon-o-clipboard-document-check')
                ->color('info')
                ->visible(fn () => auth()->user()->can('verifyInField', $this->record))
                ->requiresConfirmation()
                ->modalDescription(__('sanabel.actions.verify_in_field_help'))
                ->action(function () {
                    app(CaseService::class)->verifyInField($this->record, auth()->user());

                    Notification::make()->title(__('sanabel.actions.verified_in_field'))->success()->send();
                }),

            Actions\Action::make('endorse')
                ->label(__('sanabel.actions.endorse'))
                ->icon('heroicon-o-hand-thumb-up')
                ->color('info')
                ->visible(fn () => auth()->user()->can('endorse', $this->record))
                ->requiresConfirmation()
                ->modalDescription(__('sanabel.actions.endorse_help'))
                ->action(function () {
                    app(CaseService::class)->endorse($this->record, auth()->user());

                    Notification::make()->title(__('sanabel.actions.endorsed'))->success()->send();
                }),

            Actions\Action::make('approve')
                ->label(__('sanabel.actions.approve'))
                ->icon('heroicon-o-check-badge')
                ->color('success')
                // Separation of duties — the creator is not offered the button at all.
                ->visible(fn () => $this->record->status === 'pending_approval'
                    && auth()->user()->can('approve', $this->record))
                ->requiresConfirmation()
                ->action(function () {
                    app(CaseService::class)->approve($this->record, auth()->user());

                    Notification::make()->title(__('sanabel.actions.approved'))->success()->send();
                }),

            Actions\Action::make('reject')
                ->label(__('sanabel.actions.reject'))
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn () => auth()->user()->can('reject', $this->record))
                ->form([
                    Forms\Components\Textarea::make('reason_ar')
                        ->label(__('sanabel.actions.reason'))
                        ->required(),
                ])
                ->action(function (array $data) {
                    app(CaseService::class)->reject($this->record, auth()->user(), $data['reason_ar']);

                    Notification::make()->title(__('sanabel.actions.rejected'))->danger()->send();
                }),

            Actions\Action::make('publish')
                ->label(__('sanabel.actions.publish'))
                ->icon('heroicon-o-globe-alt')
                ->visible(fn () => auth()->user()->can('publish', $this->record))
                ->requiresConfirmation()
                ->action(function () {
                    app(CaseService::class)->publish($this->record);

                    Notification::make()->title(__('sanabel.actions.published'))->success()->send();
                }),

            /*
             | T-65 — the law being carried out, not an edit.
             |
             | Two people, as on the disbursement path: one asks and states the
             | legal basis, another carries it out, and the person who asked is
             | refused. It cannot be undone, so one person acting alone is not
             | enough. Both buttons are admin-only.
             */
            Actions\Action::make('requestAnonymise')
                ->label(__('sanabel.anonymise.request'))
                ->icon('heroicon-o-eye-slash')
                ->color('warning')
                ->visible(fn () => auth()->user()->isAdmin()
                    && ! $this->record->isAnonymised()
                    && ! $this->record->anonymise_requested_by)
                ->modalDescription(__('sanabel.anonymise.request_help'))
                ->form([
                    Forms\Components\Textarea::make('reason_ar')
                        ->label(__('sanabel.anonymise.reason'))
                        ->required()
                        ->rows(3),
                ])
                ->action(function (array $data) {
                    try {
                        app(AnonymiseService::class)
                            ->request($this->record, auth()->user(), $data['reason_ar']);
                    } catch (\RuntimeException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()->title(__('sanabel.anonymise.requested'))->success()->send();
                }),

            Actions\Action::make('executeAnonymise')
                ->label(__('sanabel.anonymise.execute'))
                ->icon('heroicon-o-eye-slash')
                ->color('danger')
                ->visible(fn () => auth()->user()->isAdmin()
                    && ! $this->record->isAnonymised()
                    && (bool) $this->record->anonymise_requested_by)
                ->requiresConfirmation()
                ->modalDescription(__('sanabel.anonymise.execute_help'))
                ->action(function () {
                    try {
                        app(AnonymiseService::class)->execute($this->record, auth()->user());
                    } catch (\RuntimeException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()->title(__('sanabel.anonymise.done'))->success()->send();
                }),

            // T-105 — closing a file is a decision about a family, and the
            // button asked whether you were the administrator instead of
            // asking the policy, which has always pointed `suspend_graduate`
            // at the executive director.
            /*
             | T-106 — the signature under the family's story. Held to
             | `approve_case` because publishing a paragraph about a
             | household to the open internet is a decision about that
             | household, and the association already named who makes those.
             */
            Actions\Action::make('approveStory')
                ->label(__('sanabel.actions.approve_story'))
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->visible(fn () => filled($this->record->story_ar)
                    && $this->record->story_approved_at === null
                    && auth()->user()->can_('approve_case'))
                ->requiresConfirmation()
                ->modalDescription(__('sanabel.actions.approve_story_help'))
                ->action(function () {
                    $this->record->forceFill([
                        'story_approved_by' => auth()->id(),
                        'story_approved_at' => now(),
                    ])->save();

                    Notification::make()->title(__('sanabel.actions.story_approved'))->success()->send();
                }),

            Actions\Action::make('close')
                ->label(__('sanabel.actions.close_case'))
                ->icon('heroicon-o-lock-closed')
                ->visible(fn () => auth()->user()->can('suspend', $this->record))
                ->requiresConfirmation()
                ->action(function () {
                    try {
                        app(CaseService::class)->close($this->record);
                        Notification::make()->title(__('sanabel.actions.closed'))->success()->send();
                    } catch (\RuntimeException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                    }
                }),
        ];
    }
}
