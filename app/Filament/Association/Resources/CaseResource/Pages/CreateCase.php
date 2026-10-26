<?php

namespace App\Filament\Association\Resources\CaseResource\Pages;

use App\Filament\Association\Resources\CaseResource;
use App\Filament\Resources\BeneficiaryResource\Pages\Concerns\AttachesCaseDocuments;
use App\Models\Beneficiary;
use App\Services\DuplicateService;
use Filament\Resources\Pages\CreateRecord;

/**
 * T-144 — «الجمعية لازم دورها من التسجيل كفقير وبعدها يذهب الى المندوب
 * الميداني», 28 October.
 *
 * The association's part of the work starts here and it had no screen for it:
 * its panel listed files and opened them, and the registration form lived in
 * the association's own panel only. It is the same form, so a household
 * registered by a partner carries the same answers as one registered by a
 * researcher, and the duplicate check runs on it exactly the same.
 */
class CreateCase extends CreateRecord
{
    use AttachesCaseDocuments;

    protected static string $resource = CaseResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = $this->pullDocuments($data);
        $data['national_id_hash'] = Beneficiary::hashNationalId((string) $data['national_id_encrypted']);
        $data['source'] = 'association';
        // A draft until the association hands it over: the field step is the
        // delegate's and the association signs nothing on the file (16 Oct).
        $data['status'] ??= 'draft';

        return $data;
    }

    protected function afterCreate(): void
    {
        app(DuplicateService::class)->flagIfSuspicious($this->record);

        // T-152 — the papers handed over while the form was being filled.
        $this->storeDocuments();
    }
}
