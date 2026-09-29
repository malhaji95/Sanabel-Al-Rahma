<?php

namespace App\Filament\Resources\BeneficiaryResource\Pages;

use App\Filament\Resources\BeneficiaryResource;
use App\Models\Beneficiary;
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

            Actions\Action::make('close')
                ->label(__('sanabel.actions.close_case'))
                ->icon('heroicon-o-lock-closed')
                ->visible(fn () => auth()->user()->isAdmin())
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
