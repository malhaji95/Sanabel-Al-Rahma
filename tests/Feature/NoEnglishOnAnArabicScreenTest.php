<?php

use App\Filament\Resources\BeneficiaryResource\Pages\EditBeneficiary;
use Filament\Facades\Filament;

/*
 | T-166 — found while photographing the file for the report: the empty
 | documents table read «لا توجد media».
 |
 | Filament names an empty table after the model behind it, and that model is
 | `Media`. §5 of CLAUDE.md says every user-facing string lives in lang/ar and
 | a hardcoded one is a bug; an English class name on an Arabic screen is the
 | same bug wearing a different coat.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    passTwoFactor();
});

it('names the documents table in Arabic when it is empty', function () {
    $case = familyOf($this->region, adults: 1, children: 0);
    $officer = userWithRole('case_officer', ['region_id' => $this->region->id]);

    $this->actingAs($officer);

    $html = Livewire\Livewire::actingAs($officer)
        ->test(EditBeneficiary::class, ['record' => $case->getRouteKey()])
        ->html();

    expect($html)->not->toContain('media')
        ->not->toContain('Media');
});

it('says what belongs on the file instead of nothing', function () {
    expect(__('sanabel.document.none'))->toBe('لا توجد وثائق على هذا الملف')
        ->and(__('sanabel.document.none_help'))->toContain('شهادة الوفاة');
});
