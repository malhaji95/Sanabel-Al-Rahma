<?php

use App\Filament\Resources\BeneficiaryResource;
use App\Filament\Resources\BeneficiaryResource\Pages\ListBeneficiaries;
use Filament\Facades\Filament;
use Filament\Tables\Columns\Column;
use Filament\Tables\Table as TableBuilder;

/*
 | T-132 — «ضمن صفحة ملفات المستفيدين عالج الظهور العرضي للصفحة كي تظهر تكون
 | الحاجة لعمل horizontal scrolling», 27 October.
 |
 | Eleven columns, seven of them always drawn, on a right-to-left page: the
 | status and the review date fell off the end. Fewer columns, and nothing
 | lost — what belongs together is drawn together.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    passTwoFactor();
});

/** The columns the list draws before anybody touches «الأعمدة». */
function columnsDrawn(): array
{
    $table = BeneficiaryResource::table(
        TableBuilder::make(new ListBeneficiaries)
    );

    return collect($table->getColumns())
        ->reject(fn (Column $column) => $column->isToggledHiddenByDefault())
        ->map(fn (Column $column) => $column->getName())
        ->values()
        ->all();
}

it('draws few enough columns to fit the page', function () {
    $drawn = columnsDrawn();

    expect(count($drawn))->toBeLessThanOrEqual(6)
        // The four a reader looks for, and the two badges beside them.
        ->and($drawn)->toContain('file_number')
        ->and($drawn)->toContain('family_name')
        ->and($drawn)->toContain('status');
});

it('loses nothing: every column taken out is still one click away', function () {
    $table = BeneficiaryResource::table(TableBuilder::make(new ListBeneficiaries));

    $hidden = collect($table->getColumns())
        ->filter(fn (Column $column) => $column->isToggledHiddenByDefault())
        ->map(fn (Column $column) => $column->getName())
        ->values();

    foreach (['region.name_ar', 'support_type', 'next_assessment_due_at', 'case_classifications'] as $name) {
        expect($hidden)->toContain($name);
    }

    // And each of them can be brought back, which is what toggleable means.
    $toggleable = collect($table->getColumns())
        ->filter(fn (Column $column) => $column->isToggledHiddenByDefault())
        ->every(fn (Column $column) => $column->isToggleable());

    expect($toggleable)->toBeTrue();
});

it('says the place and the review date under the values they belong to', function () {
    $case = publishedCase($this->region);
    $case->forceFill(['next_assessment_due_at' => now()->subDay(), 'legacy_file_no' => 'SR0000035'])->save();

    $html = Livewire\Livewire::actingAs(userWithRole('case_officer'))
        ->test(ListBeneficiaries::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$case])
        ->html();

    expect($html)->toContain($case->file_number)
        // The old system's number, under the file number.
        ->toContain('SR0000035')
        // The locality, under the family name.
        ->toContain($case->region->name_ar)
        // The overdue review, in red, under the status.
        ->toContain(__('sanabel.beneficiary.next_assessment'))
        ->toContain('text-danger-600');
});
