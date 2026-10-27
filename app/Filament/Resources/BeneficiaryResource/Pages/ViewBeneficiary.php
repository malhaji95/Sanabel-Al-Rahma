<?php

namespace App\Filament\Resources\BeneficiaryResource\Pages;

use App\Filament\Resources\BeneficiaryResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewBeneficiary extends ViewRecord
{
    /*
     | T-133 — the read-only screen of a file carries its decisions.
     |
     | An approver holds `view_full_case` and not `edit_draft`, so this is the
     | screen they can open. Every action answers to its own policy, so what
     | appears here is what the account may actually do: the editing roles see
     | the same buttons they saw before, on the screen they were already on.
     */
    use Concerns\CaseDecisionActions;
    use Concerns\ClassifiesAndNotes;

    protected static string $resource = BeneficiaryResource::class;

    /**
     * T-177 — «نحتاجه يطلع كامل», 1 November.
     *
     * It did not come out complete. The encrypted columns are `$hidden`, which
     * keeps them out of `attributesToArray()` — the array Filament fills a form
     * from — so the executive director, who may read any file in full, was
     * handed one with no national ID, no phone, no wallet and no date of birth.
     * The edit screen had put them back since it was written; this screen never
     * did, and it is the only screen that account can open.
     *
     * Named one by one, as on the edit screen, so nothing else is unhidden.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        foreach (['phone_encrypted', 'wallet_encrypted', 'national_id_encrypted', 'spouse_national_id_encrypted'] as $column) {
            $data[$column] = $this->record->{$column};
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
            $this->classifyAction(),
            ...$this->caseDecisionActions(),
        ];
    }
}
