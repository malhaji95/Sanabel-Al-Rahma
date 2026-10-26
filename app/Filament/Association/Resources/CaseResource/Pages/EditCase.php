<?php

namespace App\Filament\Association\Resources\CaseResource\Pages;

use App\Filament\Association\Resources\CaseResource;
use App\Filament\Resources\BeneficiaryResource\Pages\Concerns\AttachesCaseDocuments;
use App\Models\Beneficiary;
use App\Services\CaseService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

/**
 * T-144 — the association's own draft, and the one step that ends its part.
 */
class EditCase extends EditRecord
{
    use AttachesCaseDocuments;

    protected static string $resource = CaseResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        foreach (['phone_encrypted', 'wallet_encrypted', 'national_id_encrypted', 'spouse_national_id_encrypted'] as $column) {
            $data[$column] = $this->record->{$column};
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data = $this->pullDocuments($data);

        if (isset($data['national_id_encrypted'])) {
            $data['national_id_hash'] = Beneficiary::hashNationalId((string) $data['national_id_encrypted']);
        }

        return $data;
    }

    protected function afterSave(): void
    {
        // T-152 — the papers handed over while the form was being filled.
        $this->storeDocuments();
        $this->applyGates();
    }

    protected function getHeaderActions(): array
    {
        return [
            /*
             | «وبعدها يذهب الى المندوب الميداني». A draft is «I am still
             | typing»; this says «I have finished, it is yours now», and the
             | delegates whose region covers the household are told so.
             |
             | The consents are the model's gate and it still holds: a file
             | does not leave draft without both of them.
             */
            Actions\Action::make('sendToField')
                ->label(__('sanabel.actions.send_to_field'))
                ->icon('heroicon-o-paper-airplane')
                ->color('success')
                ->visible(fn () => auth()->user()->can('sendToField', $this->record))
                ->modalDescription(__('sanabel.actions.send_to_field_help'))
                ->requiresConfirmation()
                ->action(function () {
                    try {
                        app(CaseService::class)->sendToField($this->record, auth()->user());

                        Notification::make()->title(__('sanabel.actions.sent_to_field'))->success()->send();
                    } catch (\RuntimeException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                    }
                }),
        ];
    }
}
