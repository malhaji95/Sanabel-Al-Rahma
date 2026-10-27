<?php

use App\Filament\Resources\BeneficiaryResource\Pages\ViewBeneficiary;
use App\Models\AuditLog;
use App\Models\Beneficiary;
use App\Models\ChangeRequest;
use Filament\Facades\Filament;

/*
 | T-177 — «نحتاجه يطلع كامل ويقدر يكتب ملاحظات ويستطيع التعديل على التصنيف
 | فقط — الزكاة, الفقر, اليتم, مسكين وغيره. ولكن الآن يوقف بنفس الصفحة ويطلب
 | الرقم الوطني», 1 November, with a recording of the screen doing it.
 |
 | Three separate things were wrong, and the recording showed the third:
 | the read-only screen never decrypted the hidden columns, so the file came
 | out without its identity number; the wizard on that screen then refused to
 | move past the empty required box; and there was nowhere on it for an
 | approver to write anything that was not attached to a rejection.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->director = userWithRole('executive_director');
    passTwoFactor();
    $this->actingAs($this->director);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('hands the approver the file complete, identity number and all', function () {
    $case = publishedCase($this->region);
    $case->forceFill([
        'national_id_encrypted' => '09876543210',
        'phone_encrypted' => '0944000111',
    ])->save();

    Livewire\Livewire::test(ViewBeneficiary::class, ['record' => $case->getRouteKey()])
        ->assertFormSet([
            'national_id_encrypted' => '09876543210',
            'phone_encrypted' => '0944000111',
        ]);
});

it('never stops a reader on a box they cannot type in', function () {
    $case = publishedCase($this->region);

    // The recording: «التالي» on a screen that saves nothing, answered
    // «حقل الرقم الوطني مطلوب». A read-only wizard is walked freely.
    $page = Livewire\Livewire::test(ViewBeneficiary::class, ['record' => $case->getRouteKey()]);

    expect(wizardOf($page)->isSkippable())->toBeTrue();
});

it('lets the approver file the case under a category and say why', function () {
    $case = publishedCase($this->region, attributes: ['zakat_recommended' => true]);

    Livewire\Livewire::test(ViewBeneficiary::class, ['record' => $case->getRouteKey()])
        ->callAction('classify', [
            'zakat_category' => 'needy',
            'case_classifications' => ['poor', 'orphan'],
            'management_note_ar' => 'راجعتُ الملف, والدخل يحتاج ورقة.',
        ])
        ->assertHasNoActionErrors();

    $case->refresh();

    expect($case->zakat_category)->toBe('needy')
        ->and($case->case_classifications)->toBe(['poor', 'orphan'])
        ->and($case->management_note_ar)->toBe('راجعتُ الملف, والدخل يحتاج ورقة.');
});

it('changes nothing else on the file', function () {
    $case = publishedCase($this->region);
    $before = $case->only(['first_name', 'family_name', 'status', 'support_type', 'region_id']);

    Livewire\Livewire::test(ViewBeneficiary::class, ['record' => $case->getRouteKey()])
        ->callAction('classify', [
            'zakat_category' => 'poor',
            'case_classifications' => ['poor'],
            'management_note_ar' => 'ملاحظة',
        ]);

    expect($case->fresh()->only(array_keys($before)))->toBe($before);
})->group('AUTH-03');

it('offers the button to nobody who cannot approve a file', function () {
    $case = publishedCase($this->region);
    $officer = userWithRole('case_officer', ['region_id' => $this->region->id]);

    $this->actingAs($officer);

    Livewire\Livewire::test(ViewBeneficiary::class, ['record' => $case->getRouteKey()])
        ->assertActionHidden('classify');
});

it('keeps the approver’s note off every donor page', function () {
    $case = publishedCase($this->region);
    $case->forceFill(['management_note_ar' => 'الأسرة تسكن فوق محل أبو خالد'])->save();

    $masked = maskedOf($case->fresh());

    expect(json_encode($masked, JSON_UNESCAPED_UNICODE))
        ->not->toContain('أبو خالد')
        ->and(array_key_exists('management_note_ar', $masked))->toBeFalse();
});

it('is a field no change request can reach', function () {
    // It is the management's own note. A change request is how the data a
    // researcher typed is corrected, and this is not that.
    expect(ChangeRequest::CHANGEABLE_FIELDS)->not->toContain('management_note_ar');
});

it('writes the note into the audit log, so a replaced note is not a lost one', function () {
    $case = publishedCase($this->region);

    Livewire\Livewire::test(ViewBeneficiary::class, ['record' => $case->getRouteKey()])
        ->callAction('classify', [
            'zakat_category' => null,
            'case_classifications' => [],
            'management_note_ar' => 'الأولى',
        ]);

    Livewire\Livewire::test(ViewBeneficiary::class, ['record' => $case->getRouteKey()])
        ->callAction('classify', [
            'zakat_category' => null,
            'case_classifications' => [],
            'management_note_ar' => 'الثانية',
        ]);

    $written = AuditLog::query()
        ->where('entity_type', Beneficiary::class)
        ->where('entity_id', $case->getKey())
        ->get()
        ->filter(fn ($row) => str_contains(json_encode($row->after_json, JSON_UNESCAPED_UNICODE), 'الأولى'));

    expect($written)->not->toBeEmpty()
        ->and($case->fresh()->management_note_ar)->toBe('الثانية');
})->group('OPS-01');
