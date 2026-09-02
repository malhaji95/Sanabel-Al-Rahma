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

            Actions\Action::make('approve')
                ->label(__('sanabel.actions.approve'))
                ->icon('heroicon-o-check-badge')
                ->color('success')
                // Separation of duties — the creator is not offered the button at all.
                ->visible(fn () => auth()->user()->can('approve', $this->record))
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
