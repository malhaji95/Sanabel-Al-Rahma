<?php

namespace App\Filament\Resources\BeneficiaryResource\Pages;

use App\Filament\Resources\BeneficiaryResource;
use App\Models\Beneficiary;
use Filament\Resources\Pages\EditRecord;

class EditBeneficiary extends EditRecord
{
    use Concerns\AttachesCaseDocuments;
    use Concerns\CaseDecisionActions;

    protected static string $resource = BeneficiaryResource::class;

    /**
     * `$hidden` keeps the encrypted columns out of `attributesToArray()`, which
     * is what Filament fills the form from — so the phone and wallet of an
     * existing family arrived at the screen empty, and saving wrote the blanks
     * back over them. They are put back here, by name, so nothing else leaks.
     */
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
        return $this->caseDecisionActions();
    }
}
