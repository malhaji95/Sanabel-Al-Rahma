<?php

use App\Filament\Resources\BeneficiaryResource;
use App\Models\Beneficiary;
use App\Models\Debt;
use App\Models\User;
use App\Services\NeedEngine;
use App\Services\ScoreService;

/*
 | T-302 — «ربط عامل المديونية تلقائيًا بالديون التي تم التحقق منها واعتماد
 | أهليتها», 12 November.
 |
 | The file held a table of creditors and, quite separately, a box a
 | researcher typed a figure into. The score read the box, so the number that
 | moved a family up the queue need not have matched the debts the family
 | named, and nothing would notice.
 |
 | Their acceptance test: several debts, some verified and some not, and the
 | points resting only on the ones that are verified and eligible.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->case = familyOf($this->region, adults: 2, children: 2);
    $this->checker = userWithRole('case_officer', ['region_id' => $this->region->id]);
});

/** One debt on the file, in whichever of the three states is asked for. */
function debtOf(Beneficiary $case, int $amount, string $state, ?User $by = null): Debt
{
    return Debt::create([
        'beneficiary_id' => $case->getKey(),
        'creditor_name_ar' => 'دائن تجريبي',
        'amount' => $amount,
        'currency' => config('sanabel.currency'),
        'reason_ar' => 'طعام ودواء',
        'is_essential' => match ($state) {
            'eligible' => true,
            'not_essential' => false,
            default => null,
        },
        'verification_method' => in_array($state, ['eligible', 'not_essential', 'verified_unplaced'], true)
            ? 'document'
            : null,
        'verification_note_ar' => 'سند مرفق',
        'verified_by' => $by?->getKey(),
        'verified_at' => in_array($state, ['eligible', 'not_essential', 'verified_unplaced'], true)
            ? now()
            : null,
    ]);
}

it('counts the verified and essential debt, and nothing else', function () {
    debtOf($this->case, 100_000, 'eligible', $this->checker);     // counts
    debtOf($this->case, 400_000, 'recorded');                      // unverified
    debtOf($this->case, 800_000, 'not_essential', $this->checker); // verified, not essential
    debtOf($this->case, 200_000, 'verified_unplaced', $this->checker); // «أساسي؟» unanswered

    expect(ScoreService::eligibleDebt($this->case->refresh()))->toBe(100_000);
});

it('treats an unanswered «أساسي؟» as unanswered, not as a yes', function () {
    /*
     | The whole point of the third state. A debt nobody has placed scores
     | nothing, rather than scoring on an assumption nobody made.
     */
    $debt = debtOf($this->case, 500_000, 'verified_unplaced', $this->checker);

    expect($debt->isVerified())->toBeTrue()
        ->and($debt->isEligible())->toBeFalse()
        ->and(ScoreService::eligibleDebt($this->case->refresh()))->toBe(0);
});

it('moves the debt factor only when an eligible debt is on the file', function () {
    $engine = app(NeedEngine::class);
    $score = app(ScoreService::class);

    $before = $score->compute($this->case, $engine->compute($this->case))['factors']['B'];

    debtOf($this->case, 900_000, 'recorded');
    $unverified = $score->compute($this->case->refresh(), $engine->compute($this->case))['factors']['B'];

    debtOf($this->case, 900_000, 'eligible', $this->checker);
    $verified = $score->compute($this->case->refresh(), $engine->compute($this->case))['factors']['B'];

    expect($before)->toBe(0.0)
        ->and($unverified)->toBe(0.0)
        ->and($verified)->toBeGreaterThan(0.0);
});

it('no longer reads the figure a researcher could type beside the table', function () {
    /*
     | «منع الباحث من إدخال إجمالي مستقل يخالف الديون المعتمدة». The column is
     | left on the row — nothing is deleted here — but the score has stopped
     | listening to it.
     */
    $this->case->forceFill(['documented_debt' => 5_000_000])->save();

    $score = app(ScoreService::class)->compute(
        $this->case->refresh(),
        app(NeedEngine::class)->compute($this->case),
    );

    expect($score['factors']['B'])->toBe(0.0);
});

it('keeps the signature only while the means it rests on is there', function () {
    // Clearing the means unverifies the row, rather than leaving it carrying
    // a check nobody can now point at.
    $data = BeneficiaryResource::stampDebtVerification([
        'verification_method' => null,
        'verified_by' => 7,
        'verified_at' => now()->subDay(),
    ]);

    expect($data['verified_by'])->toBeNull()
        ->and($data['verified_at'])->toBeNull();
});

it('stamps who checked it and when, from the act of naming the means', function () {
    $this->actingAs($this->checker);

    $data = BeneficiaryResource::stampDebtVerification([
        'verification_method' => 'creditor_statement',
    ]);

    expect($data['verified_by'])->toBe($this->checker->getKey())
        ->and($data['verified_at'])->not->toBeNull();
});

it('leaves recording a debt apart from deciding to pay it', function () {
    /*
     | «إبقاء قرار سداد الدين منفصلًا عن مجرد تسجيله أو احتسابه ضمن الأولوية».
     | An eligible debt raises the score and adds no money: the need is what
     | the reference values and the approved items make it, and nothing here
     | reaches it.
     */
    $engine = app(NeedEngine::class);

    $before = $engine->compute($this->case)['monthly_need'];
    debtOf($this->case, 900_000, 'eligible', $this->checker);

    expect($engine->compute($this->case->refresh())['monthly_need'])->toBe($before);
});
