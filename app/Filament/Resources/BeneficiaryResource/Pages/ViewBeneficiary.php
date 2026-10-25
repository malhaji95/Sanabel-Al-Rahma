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

    protected static string $resource = BeneficiaryResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\EditAction::make(), ...$this->caseDecisionActions()];
    }
}
