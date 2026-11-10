<?php

use App\Filament\Resources\BeneficiaryResource\Pages\ViewBeneficiary;
use App\Filament\Resources\BeneficiaryResource\RelationManagers\NeedAdditionsRelationManager;
use App\Models\Beneficiary;
use App\Models\HealthRecord;
use App\Models\NeedAddition;
use App\Models\User;
use App\Services\AssessmentService;
use App\Services\NeedEngine;
use Filament\Facades\Filament;

/*
 | T-289 — the association's points 8 and 9, 10 November: the medical cost
 | and the documented debt must not be added to the monthly need by
 | themselves, and there must be a way to approve an amount towards one.
 |
 | The first half was already true. The second half existed in the engine and
 | no screen ever reached it, so these tests hold both ends: nothing is
 | added without a decision, and a decision is added.
 */
beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->case = familyOf($this->region, adults: 2, children: 1);
    $this->approver = userWithRole('executive_director');
});

/** An approved addition in force from this month. */
function approvedAddition(Beneficiary $case, User $by, int $amount, string $kind = 'medical'): NeedAddition
{
    return NeedAddition::create([
        'beneficiary_id' => $case->id,
        'kind' => $kind,
        'amount' => $amount,
        'currency' => config('sanabel.currency'),
        'reason_ar' => 'دواء ضغط شهري, وصفة مرفقة',
        'starts_on' => now()->startOfMonth(),
        'approved_by' => $by->id,
        'approved_at' => now(),
    ]);
}

it('adds nothing to the need for a medical cost or a debt on their own', function () {
    $before = app(NeedEngine::class)->compute($this->case->refresh())['monthly_need'];

    HealthRecord::factory()->create([
        'beneficiary_id' => $this->case->id,
        'monthly_medical_cost' => 90_000,
    ]);

    $this->case->update(['documented_debt' => 500_000]);

    expect(app(NeedEngine::class)->compute($this->case->refresh())['monthly_need'])->toBe($before);
});

it('adds an amount the association approved', function () {
    $before = app(NeedEngine::class)->compute($this->case->refresh())['monthly_need'];

    approvedAddition($this->case, $this->approver, 40_000);

    expect(app(NeedEngine::class)->compute($this->case->refresh())['monthly_need'])
        ->toBe($before + 40_000);
});

it('adds nothing for a row nobody approved yet', function () {
    $before = app(NeedEngine::class)->compute($this->case->refresh())['monthly_need'];

    NeedAddition::create([
        'beneficiary_id' => $this->case->id,
        'kind' => 'debt',
        'amount' => 40_000,
        'currency' => config('sanabel.currency'),
        'reason_ar' => 'اقتراحٌ لم يُعتمد',
        'starts_on' => now()->startOfMonth(),
    ]);

    expect(app(NeedEngine::class)->compute($this->case->refresh())['monthly_need'])->toBe($before);
});

it('keeps it out of the months it was not approved for', function () {
    NeedAddition::create([
        'beneficiary_id' => $this->case->id,
        'kind' => 'debt',
        'amount' => 40_000,
        'currency' => config('sanabel.currency'),
        'reason_ar' => 'ثلاثة أشهر لا غير',
        'starts_on' => now()->startOfMonth(),
        'ends_on' => now()->startOfMonth()->addMonths(2),
        'approved_by' => $this->approver->id,
        'approved_at' => now(),
    ]);

    $needAt = fn ($when) => app(NeedEngine::class)->compute($this->case->refresh(), $when)['monthly_need'];

    expect($needAt(now()))->toBeGreaterThan($needAt(now()->copy()->addMonths(4)))
        ->and($needAt(now()->copy()->subMonth()))->toBe($needAt(now()->copy()->addMonths(4)));
});

it('stays in a study already computed even after it is stopped', function () {
    $addition = approvedAddition($this->case, $this->approver, 40_000);
    app(AssessmentService::class)->create($this->case->refresh(), status: 'approved');

    $need = $this->case->refresh()->currentAssessment()->monthly_need;

    $addition->update(['ends_on' => now()->startOfMonth()->subMonth()]);

    // Rule 8: the study keeps what it was computed from.
    expect($this->case->refresh()->currentAssessment()->monthly_need)->toBe($need);
});

it('says on the breakdown what the money was for and who approved it', function () {
    approvedAddition($this->case, $this->approver, 40_000);
    app(AssessmentService::class)->create($this->case->refresh(), status: 'approved');

    $row = collect($this->case->refresh()->currentAssessment()->needBreakdown()['need'])
        ->firstWhere('kind', 'addition');

    expect($row['total'])->toBe(40_000)
        ->and($row['key'])->toBe('medical')
        ->and($row['reason_ar'])->toBe('دواء ضغط شهري, وصفة مرفقة')
        ->and($row['approved_by'])->toBe($this->approver->name);
});

it('offers the decision to the hand that approves a case, and to no other', function () {
    passTwoFactor();
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $mount = fn () => Livewire\Livewire::test(NeedAdditionsRelationManager::class, [
        'ownerRecord' => $this->case->refresh(),
        'pageClass' => ViewBeneficiary::class,
    ]);

    $this->actingAs($this->approver);
    $mount()->assertTableActionExists('approve', record: approvedAdditionPending($this->case));

    // A researcher records the file and does not approve money onto it.
    $this->actingAs(userWithRole('case_officer'));
    $mount()->assertTableActionHidden('approve', approvedAdditionPending($this->case));
});

/** A row waiting for a decision. */
function approvedAdditionPending(Beneficiary $case): NeedAddition
{
    return NeedAddition::firstOrCreate(
        ['beneficiary_id' => $case->id, 'kind' => 'other'],
        [
            'amount' => 10_000,
            'currency' => config('sanabel.currency'),
            'reason_ar' => 'بانتظار قرار',
            'starts_on' => now()->startOfMonth(),
        ],
    );
}
