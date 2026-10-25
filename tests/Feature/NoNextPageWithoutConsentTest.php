<?php

use App\Filament\Resources\BeneficiaryResource\Pages\EditBeneficiary;
use App\Models\Beneficiary;
use Filament\Facades\Filament;
use Filament\Forms\Components\Wizard\Step;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;

/*
 | T-131 — «عند عدم اختيار الموافقة على سياسة الخصوصية واطلعت على سياسة
 | الخصوصية لا يجب ان تنتقل الى الصفحة التالية لعدم الموافقة وتظهر خطأ»,
 | 27 October.
 |
 | The model has refused a file leaving draft without both answers since T-74.
 | That is the hard stop, and it arrives at the end. This is the page where
 | the two questions are asked refusing to hand over until they are answered.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    passTwoFactor();
});

/**
 * Answers the consents page, presses «التالي», and says what came back: the
 * exception the page threw to stop the move, or null when it handed over.
 *
 * The step is read from the component as it stands after the answers, because
 * every Livewire call builds the form again — a step captured beforehand
 * carries the state of the request it was built in.
 */
function leaveConsents(Beneficiary $case, array $answers): ?Throwable
{
    $page = Livewire\Livewire::actingAs(userWithRole('case_officer'))
        ->test(EditBeneficiary::class, ['record' => $case->getRouteKey()]);

    foreach ($answers as $field => $value) {
        $page->set("data.{$field}", $value);
    }

    $step = collect($page->instance()->form->getComponents()[0]->getChildComponents())
        ->first(fn (Step $step) => $step->getLabel() === __('sanabel.beneficiary.step_consents'));

    expect($step)->not->toBeNull();

    try {
        $step->callAfterValidation();
    } catch (Throwable $thrown) {
        return $thrown;
    }

    return null;
}

/** The last thing the screen said. */
function lastNotice(): ?string
{
    $sent = session('filament.notifications') ?? [];

    return $sent === [] ? null : Notification::fromArray(end($sent))->getTitle();
}

it('refuses to hand the page over until both answers are given', function () {
    $case = familyOf($this->region);

    // Neither answered.
    expect(leaveConsents($case, ['privacy_policy_seen' => false, 'privacy_consent' => false]))
        ->toBeInstanceOf(Halt::class);

    // Shown the policy, and not agreed to it.
    expect(leaveConsents($case, ['privacy_policy_seen' => true, 'privacy_consent' => false]))
        ->toBeInstanceOf(Halt::class);

    // Both given: the page hands over.
    expect(leaveConsents($case, ['privacy_policy_seen' => true, 'privacy_consent' => true]))
        ->toBeNull();
});

it('says which of the two answers is missing', function () {
    $case = familyOf($this->region);

    // The reading comes first: there is nothing to agree to before it.
    leaveConsents($case, ['privacy_policy_seen' => false, 'privacy_consent' => false]);

    expect(lastNotice())->toBe(__('sanabel.beneficiary.privacy_needs_seen_first'));
});

it('asks for the agreement once the policy has been shown', function () {
    $case = familyOf($this->region);

    leaveConsents($case, ['privacy_policy_seen' => true, 'privacy_consent' => false]);

    expect(lastNotice())->toBe(__('sanabel.beneficiary.privacy_consent_required'));
});

it('still lets a file be saved as a draft before the consent page is reached', function () {
    // T-130 — what a validation rule on the two toggles would have cost.
    $case = familyOf($this->region);

    Livewire\Livewire::actingAs(userWithRole('case_officer'))
        ->test(EditBeneficiary::class, ['record' => $case->getRouteKey()])
        ->fillForm(['town_ar' => 'حي المنشية'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($case->refresh()->town_ar)->toBe('حي المنشية')
        ->and($case->status)->toBe('draft');
});

it('keeps the hard stop on the file leaving draft', function () {
    // The model's refusal since T-74, which this task does not relax.
    $case = familyOf($this->region);

    $this->actingAs(userWithRole('executive_director'));

    expect(fn () => $case->forceFill(['status' => 'pending_visit'])->save())
        ->toThrow(RuntimeException::class);
})->group('GOV-01');
