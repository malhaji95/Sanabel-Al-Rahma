<?php

namespace App\Filament\Resources\DisbursementResource\Pages;

use App\Filament\Resources\DisbursementResource;
use App\Models\Beneficiary;
use App\Services\DisbursementService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateDisbursement extends CreateRecord
{
    protected static string $resource = DisbursementResource::class;

    /**
     * The service owns the rule, not the form: the amount is checked against
     * what the family's month still needs, and the confirming officer is
     * recorded on the line.
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(DisbursementService::class)->confirm(
            Beneficiary::findOrFail($data['beneficiary_id']),
            (int) $data['amount'],
            auth()->user(),
        );
    }
}
