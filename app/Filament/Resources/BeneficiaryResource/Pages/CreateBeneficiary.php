<?php

namespace App\Filament\Resources\BeneficiaryResource\Pages;

use App\Filament\Resources\BeneficiaryResource;
use App\Models\Beneficiary;
use App\Services\DuplicateService;
use Filament\Resources\Pages\CreateRecord;

class CreateBeneficiary extends CreateRecord
{
    protected static string $resource = BeneficiaryResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['national_id_hash'] = Beneficiary::hashNationalId((string) $data['national_id_encrypted']);
        $data['source'] = auth()->user()->hasRole('association') ? 'association' : 'delegate';
        $data['status'] ??= 'draft';

        return $data;
    }

    protected function afterCreate(): void
    {
        // Similar phone or wallet raises a review flag — never an auto-merge.
        app(DuplicateService::class)->flagIfSuspicious($this->record);
    }
}
