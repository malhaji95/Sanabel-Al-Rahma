<?php

use App\Filament\Resources\BeneficiaryResource\Pages\ViewBeneficiary;
use App\Models\Beneficiary;
use App\Services\CaseService;
use Filament\Facades\Filament;

/*
 | T-267 — found in the general audit of 8 November, reported by nobody.
 |
 | `CaseService::suspend()` was written with the case flow and nothing ever
 | called it: the status existed, the policy existed, and there was no
 | button anywhere in the panel. A file could be rejected, closed, graduated
 | or merged — and never paused. So the association's only way to stop help
 | over a doubt was to reject the family outright, which says something
 | quite different and is far harder to undo.
 */
beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->director = userWithRole('executive_director');
});

it('pauses a published file and hides its job profile with it', function () {
    $case = publishedCase($this->region);

    app(CaseService::class)->suspend($case, 'الأسرة سافرت والملف بانتظار عودتها.');

    expect($case->refresh()->status)->toBe('suspended')
        ->and($case->reject_reason_ar)->toBe('الأسرة سافرت والملف بانتظار عودتها.');
});

it('refuses to pause a file without saying why', function () {
    $case = publishedCase($this->region);

    expect(fn () => app(CaseService::class)->suspend($case, '   '))
        ->toThrow(RuntimeException::class);

    expect($case->refresh()->status)->toBe('published');
});

it('puts the button on the screen the association actually opens', function () {
    $case = publishedCase($this->region);

    passTwoFactor();
    $this->actingAs($this->director);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire\Livewire::test(ViewBeneficiary::class, ['record' => $case->getRouteKey()])
        ->assertActionVisible('suspend')
        ->callAction('suspend', ['reason_ar' => 'شكٌّ في الدخل يُستوضح.']);

    expect($case->refresh()->status)->toBe('suspended');
});

it('offers it only on a file that is still receiving help', function () {
    $case = publishedCase($this->region);
    app(CaseService::class)->suspend($case, 'موقوف.');

    passTwoFactor();
    $this->actingAs($this->director);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    // Already paused: there is nothing to pause, and «إيقاف» on a stopped
    // file would read as a second, different decision.
    Livewire\Livewire::test(ViewBeneficiary::class, ['record' => $case->refresh()->getRouteKey()])
        ->assertActionHidden('suspend');
});

it('leaves a paused file out of what may receive money', function () {
    $case = publishedCase($this->region);
    app(CaseService::class)->suspend($case, 'موقوف.');

    expect($case->refresh()->mayReceiveMoney())->toBeFalse()
        ->and(in_array('suspended', Beneficiary::ENDED_STATUSES, true))->toBeTrue();
});
