<?php

use App\Filament\Resources\BeneficiaryResource\Pages\EditBeneficiary;
use App\Filament\Resources\BeneficiaryResource\Pages\ListBeneficiaries;
use App\Models\Beneficiary;
use App\Services\CaseService;
use Filament\Facades\Filament;

/*
 | T-206 — «المدير التنفيذي أعطى إعادة تعديل, إرجاع إلى المندوب. هو المفروض
 | ما يظهر بانتظار زيارة; لازم يظهر أنه هذا تم إرجاعه من قبل المدير, مع
 | إظهار أي شيء ملاحظة», 30 October. And with it: «ما ظهر سبب الرفض, بحيث
 | تظهر للمندوب والمشرف».
 |
 | Two faults, one cause. A file handed back was written as «بانتظار
 | الزيارة», which is also what a file nobody has visited says; and the note
 | that came with it was on the record and on no screen.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->delegate = userWithRole('delegate', ['region_id' => $this->region->id]);
    $this->director = userWithRole('executive_director');
    passTwoFactor();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

/** A file the reviewer handed back, with the note that sent it. */
function returnedFile($region, $delegate, $director, string $note = 'ينقص إثبات الإيجار.')
{
    $case = familyOf($region, adults: 1, children: 0);
    $case->forceFill(['status' => 'pending_approval', 'created_by' => $delegate->id])->save();

    return app(CaseService::class)->returnToField($case, $director, $note);
}

it('calls it «أُعيد للتعديل», not «بانتظار الزيارة»', function () {
    $case = returnedFile($this->region, $this->delegate, $this->director);

    expect($case->status)->toBe('returned')
        ->and(__('sanabel.status.returned'))->toBe('أُعيد للتعديل');
});

it('keeps it in the delegate’s queue, which is where it belongs', function () {
    expect(CaseService::AWAITING_FIELD)->toContain('returned');
});

it('shows the note on the file itself, above everything else', function () {
    $case = returnedFile($this->region, $this->delegate, $this->director, 'صورة الهوية غير واضحة.');

    $this->actingAs($this->delegate);

    expect(Livewire\Livewire::test(EditBeneficiary::class, ['record' => $case->getRouteKey()])->html())
        ->toContain('صورة الهوية غير واضحة.')
        ->toContain(e(__('sanabel.cases.review_note_returned')));
});

it('shows the reason on the list, under the status it explains', function () {
    returnedFile($this->region, $this->delegate, $this->director, 'ينقص كشف الدخل.');

    $this->actingAs($this->delegate);

    Livewire\Livewire::test(ListBeneficiaries::class)->assertSee('ينقص كشف الدخل.');
});

it('shows a refusal’s reason to the supervisor too', function () {
    $supervisor = userWithRole('area_supervisor', ['region_id' => $this->region->id]);

    $case = familyOf($this->region, adults: 1, children: 0);
    $case->forceFill(['status' => 'pending_approval'])->save();
    declaresNoTie($this->director, $case); // T-305
    app(CaseService::class)->reject($case, $this->director, 'الأسرة مسجّلة في جمعية أخرى.');

    $this->actingAs($supervisor);

    Livewire\Livewire::test(ListBeneficiaries::class)
        ->assertSee('الأسرة مسجّلة في جمعية أخرى.');
});

it('lets the delegate correct it and send it back up the same path', function () {
    $case = returnedFile($this->region, $this->delegate, $this->director);

    // The delegate signs it again, the supervisor endorses, the director
    // approves. The path out of «أُعيد للتعديل» is the path in.
    $supervisor = userWithRole('area_supervisor', ['region_id' => $this->region->id]);

    declaresNoTie($this->delegate, $case); // T-305
    $case = app(CaseService::class)->verifyInField($case, $this->delegate);
    expect($case->status)->toBe('verified');

    declaresNoTie($supervisor, $case); // T-305
    $case = app(CaseService::class)->endorse($case, $supervisor);
    expect($case->status)->toBe('pending_approval');

    declaresNoTie($this->director, $case); // T-305
    $case = app(CaseService::class)->approve($case, $this->director);
    expect($case->status)->toBe('approved');
});

it('refuses to hand a file back without saying why', function () {
    $case = familyOf($this->region, adults: 1, children: 0);
    $case->forceFill(['status' => 'pending_approval'])->save();

    expect(fn () => app(CaseService::class)->returnToField($case, $this->director, ''))
        ->toThrow(RuntimeException::class);
});

it('counts the returned files on their own card, for the delegate and the supervisor', function () {
    returnedFile($this->region, $this->delegate, $this->director);

    expect(Beneficiary::where('status', 'returned')->count())->toBe(1)
        ->and(__('sanabel.dashboard.returned_files'))->toBe('ملفات أُعيدت للتعديل')
        ->and(__('sanabel.dashboard.rejected_files'))->toBe('ملفات مرفوضة');
});
