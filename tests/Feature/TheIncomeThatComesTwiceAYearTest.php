<?php

use App\Filament\Resources\BeneficiaryResource\Pages\EditBeneficiary;
use App\Models\Beneficiary;
use App\Models\Income;
use App\Services\NeedEngine;
use Filament\Facades\Filament;

/*
 | T-287 — «بالنسبة للدخل الموسمي، نحتاج معرفة كيف يُحول إلى متوسط شهري»,
 | and «الموسمية او الجيران او الجمعيات ممكن تقسم على السنة او تكون وسطي»,
 | 10 November.
 |
 | An income was steady and deducted, or it was not and was deducted from
 | nothing. Land rent collected once a year fell in the second, and that is
 | money the household lives on all year. The screen told the researcher to
 | divide by twelve themselves; now the row says how often and the engine
 | divides, where it can be read.
 */
beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->case = familyOf($this->region, adults: 2, children: 1);
});

/** What the engine deducts for this household. */
function deducted(Beneficiary $case): int
{
    return app(NeedEngine::class)->compute($case->refresh())['stable_income'];
}

it('spreads a yearly rent over the year it is lived on', function () {
    Income::create([
        'beneficiary_id' => $this->case->id,
        'source_type' => 'أجرة أرض',
        'amount' => 120_000,
        'currency' => config('sanabel.currency'),
        'is_stable' => false,
        'recurrence' => 'seasonal',
        'times_per_year' => 1,
    ]);

    expect(deducted($this->case))->toBe(10_000);
});

it('counts a help that comes at both feasts twice', function () {
    Income::create([
        'beneficiary_id' => $this->case->id,
        'source_type' => 'إعانة أقارب',
        'amount' => 60_000,
        'currency' => config('sanabel.currency'),
        'is_stable' => false,
        'recurrence' => 'seasonal',
        'times_per_year' => 2,
    ]);

    // 60,000 twice a year is 120,000, which is 10,000 a month.
    expect(deducted($this->case))->toBe(10_000);
});

it('still deducts nothing from an income nobody counts on', function () {
    Income::create([
        'beneficiary_id' => $this->case->id,
        'source_type' => 'عمل متقطّع',
        'amount' => 500_000,
        'currency' => config('sanabel.currency'),
        'is_stable' => false,
        'recurrence' => 'intermittent',
        'times_per_year' => null,
    ]);

    expect(deducted($this->case))->toBe(0);
});

it('leaves a steady income exactly as it was', function () {
    Income::create([
        'beneficiary_id' => $this->case->id,
        'source_type' => 'راتب',
        'amount' => 75_000,
        'currency' => config('sanabel.currency'),
        'is_stable' => true,
    ]);

    expect(deducted($this->case))->toBe(75_000);
});

it('adds the three kinds together, each counted its own way', function () {
    foreach ([
        ['راتب', 50_000, true, null],
        ['أجرة أرض', 120_000, false, 1],
        ['هدية مرّة واحدة', 300_000, false, null],
    ] as [$source, $amount, $stable, $times]) {
        Income::create([
            'beneficiary_id' => $this->case->id,
            'source_type' => $source,
            'amount' => $amount,
            'currency' => config('sanabel.currency'),
            'is_stable' => $stable,
            'recurrence' => $stable ? null : 'seasonal',
            'times_per_year' => $times,
        ]);
    }

    expect(deducted($this->case))->toBe(50_000 + 10_000);
});

it('never turns a part of a pound into a pound', function () {
    Income::create([
        'beneficiary_id' => $this->case->id,
        'source_type' => 'إعانة',
        'amount' => 1000,
        'currency' => config('sanabel.currency'),
        'is_stable' => false,
        'recurrence' => 'seasonal',
        'times_per_year' => 1,
    ]);

    // 1,000 ÷ 12 is 83.33, and the household is credited with 83, never 84.
    expect(deducted($this->case))->toBe(83);
});

it('asks how often on the screen, and counts it into the figure shown there', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    passTwoFactor();

    Livewire\Livewire::actingAs(userWithRole('case_officer'))
        ->test(EditBeneficiary::class, ['record' => $this->case->getRouteKey()])
        ->fillForm(['has_income' => 'yes', 'incomes' => [
            ['source_type' => 'أجرة أرض', 'amount' => 120_000, 'is_stable' => false,
                'recurrence' => 'seasonal', 'times_per_year' => 1],
        ]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(deducted($this->case))->toBe(10_000);
});
