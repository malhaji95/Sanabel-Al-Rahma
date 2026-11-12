<?php

use App\Models\NeedAddition;
use App\Models\SupportSource;
use App\Services\AssessmentService;
use App\Services\CoverageService;
use App\Services\NeedEngine;

/*
 | T-304 — «ربط المساعدة المخصصة ببند الاحتياج الذي تغطيه», 12 November.
 |
 | T-280 stopped an earmarked payment being deducted from the family's living
 | need, which was right: nobody's grocery bill shrinks because a neighbour
 | paid for their medicine. It left the payment deducted from nothing at all.
 |
 | So the association's own case: Sanabel approves an amount towards a course
 | of treatment, another body pays for that same treatment, and the approved
 | amount goes on being collected.
 |
 | Their acceptance test: «اعتماد علاج بقيمة معينة، ثم تسجيل تغطية جزئية
 | وتغطية كاملة من جهة أخرى، والتأكد من أن المتبقي يتغير فقط في بند العلاج
 | نفسه».
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->case = familyOf($this->region, adults: 2, children: 2);
    $this->engine = app(NeedEngine::class);
    $this->month = app(CoverageService::class)->currentMonth();

    $this->treatment = NeedAddition::create([
        'beneficiary_id' => $this->case->getKey(),
        'kind' => 'medical',
        'amount' => 60_000,
        'currency' => config('sanabel.currency'),
        'reason_ar' => 'دواء ضغط شهري',
        'starts_on' => now()->startOfMonth()->toDateString(),
        'approved_at' => now(),
    ]);
});

/** A payment from somewhere else, for a named thing, against this month. */
function paidTowards(NeedAddition $item, int $amount): SupportSource
{
    return SupportSource::create([
        'beneficiary_id' => $item->beneficiary_id,
        'name_ar' => 'جمعية البر',
        'kind' => 'one_off',
        'amount' => $amount,
        'purpose' => 'earmarked',
        'purpose_note_ar' => 'ثمن الدواء',
        'need_addition_id' => $item->getKey(),
        'coverage_month' => app(CoverageService::class)->currentMonth()->toDateString(),
        'paid_on' => now()->toDateString(),
    ]);
}

it('asks for the whole approved amount while nobody else has paid', function () {
    $need = $this->engine->compute($this->case->refresh());
    $item = collect($need['snapshot']['need_additions'])->first();

    expect($item['covered'])->toBe(0)
        ->and($item['net'])->toBe(60_000)
        ->and($item['coverage_state'])->toBe('none')
        ->and($need['snapshot']['need_additions_total'] ?? null)->not->toBeNull();
});

it('asks for what is left when another body pays part of it', function () {
    $before = $this->engine->compute($this->case->refresh())['monthly_need'];

    paidTowards($this->treatment, 20_000);

    $need = $this->engine->compute($this->case->refresh());
    $item = collect($need['snapshot']['need_additions'])->first();

    expect($item['covered'])->toBe(20_000)
        ->and($item['net'])->toBe(40_000)
        ->and($item['coverage_state'])->toBe('partial')
        // And the need fell by exactly that, and by nothing else.
        ->and($need['monthly_need'])->toBe($before - 20_000);
});

it('asks for nothing when another body pays the whole of it', function () {
    $before = $this->engine->compute($this->case->refresh())['monthly_need'];

    paidTowards($this->treatment, 60_000);

    $need = $this->engine->compute($this->case->refresh());
    $item = collect($need['snapshot']['need_additions'])->first();

    expect($item['covered'])->toBe(60_000)
        ->and($item['net'])->toBe(0)
        ->and($item['coverage_state'])->toBe('full')
        ->and($need['monthly_need'])->toBe($before - 60_000);
});

it('never turns an overpayment into a credit against the family’s bread', function () {
    $before = $this->engine->compute($this->case->refresh())['monthly_need'];

    paidTowards($this->treatment, 200_000);

    expect($this->treatment->refresh()->netAmount())->toBe(0)
        ->and($this->engine->compute($this->case->refresh())['monthly_need'])
        ->toBe($before - 60_000);
});

it('moves only the item it was paid towards, and no other', function () {
    /*
     | «والتأكد من أن المتبقي يتغير فقط في بند العلاج نفسه». A second approved
     | item on the same file, untouched by a payment made for the first.
     */
    $debt = NeedAddition::create([
        'beneficiary_id' => $this->case->getKey(),
        'kind' => 'debt',
        'amount' => 25_000,
        'currency' => config('sanabel.currency'),
        'reason_ar' => 'قسط دين ضروري',
        'starts_on' => now()->startOfMonth()->toDateString(),
        'approved_at' => now(),
    ]);

    paidTowards($this->treatment, 60_000);

    $items = collect($this->engine->compute($this->case->refresh())['snapshot']['need_additions'])
        ->keyBy('id');

    expect($items[$this->treatment->getKey()]['net'])->toBe(0)
        ->and($items[$debt->getKey()]['net'])->toBe(25_000)
        ->and($items[$debt->getKey()]['coverage_state'])->toBe('none');
});

it('leaves the family’s living need alone, which is what T-280 settled', function () {
    /*
     | «عدم خصم المساعدة العلاجية من مصروف المعيشة العام إذا لم تكن تغطيه».
     | The payment comes off the item it names and off nothing else: the
     | reference values, the rent and the rest are untouched, and the stable
     | income does not move either — this is not income.
     */
    $before = $this->engine->compute($this->case->refresh());

    paidTowards($this->treatment, 60_000);
    $after = $this->engine->compute($this->case->refresh());

    expect($after['snapshot']['members_total'])->toBe($before['snapshot']['members_total'])
        ->and($after['stable_income'])->toBe($before['stable_income'])
        ->and($after['snapshot']['external_support'])->toBe(0);
});

it('answers the item whole when the health path is treating them', function () {
    /*
     | «مراعاة الخدمات التي يقدمها المسار الصحي». It carries no figure — it
     | routes the family to a provider who treats them — so it is a statement
     | rather than an amount, and the item is recorded and funded at nothing.
     */
    $before = $this->engine->compute($this->case->refresh())['monthly_need'];

    $this->treatment->forceFill([
        'covered_by_health_path' => true,
        'coverage_note_ar' => 'محوَّلة إلى مشفى متعاقد',
    ])->save();

    $need = $this->engine->compute($this->case->refresh());
    $item = collect($need['snapshot']['need_additions'])->first();

    expect($item['coverage_state'])->toBe('full')
        ->and($item['net'])->toBe(0)
        ->and($need['monthly_need'])->toBe($before - 60_000);
});

it('counts a payment against the month it answers and no other', function () {
    paidTowards($this->treatment, 60_000);

    // This month it is covered; next month the treatment is needed again.
    expect($this->treatment->refresh()->coveredAmount($this->month))->toBe(60_000)
        ->and($this->treatment->coveredAmount($this->month->copy()->addMonth()))->toBe(0);
});

it('keeps the lines of the table adding to the figure above them', function () {
    // The invariant the screen rests on: whatever coverage does, the need
    // rows still sum to the need.
    paidTowards($this->treatment, 20_000);

    $assessment = app(AssessmentService::class)
        ->create($this->case->refresh(), status: 'approved');

    $breakdown = $assessment->needBreakdown();

    expect(collect($breakdown['need'])->sum('total'))
        ->toBe($breakdown['totals']['monthly_need']);
});
