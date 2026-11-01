<?php

use App\Filament\Resources\BeneficiaryResource\Pages\ViewBeneficiary;
use App\Models\Beneficiary;
use Filament\Facades\Filament;

/*
 | T-212 — «نعم يمكن للمدير التنفيذي الكتابة», 1 November, answering what
 | T-211 left open.
 |
 | He could put his name under a family's story and could not write one: the
 | textarea lives on the edit screen, and T-177 keeps an approver off it on
 | purpose — the account that decides about families does not type the
 | families' data in. A published file with no story therefore had an
 | approver standing in front of a button hidden for want of anything to
 | approve.
 |
 | So the story joins the one modal an approver may write in, and not the
 | edit screen. The story is the association's own words about a household
 | rather than the household's data, which is the line the permission table
 | is drawn along.
 |
 | Signing stays a second act. The model withdraws a signature the moment
 | the words change (T-106), so nothing typed here reaches a donor until the
 | approve button is pressed.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->director = userWithRole('executive_director');
    passTwoFactor();
    $this->actingAs($this->director);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

/** The modal, filled with whatever the caller cares about. */
function classify(Beneficiary $case, array $data = []): void
{
    Livewire\Livewire::test(ViewBeneficiary::class, ['record' => $case->getRouteKey()])
        ->callAction('classify', array_merge([
            'zakat_category' => null,
            'case_classifications' => [],
            'story_ar' => null,
            'management_note_ar' => null,
        ], $data))
        ->assertHasNoActionErrors();
}

it('lets the approver write the story from the file he is reading', function () {
    $case = publishedCase($this->region);

    classify($case, ['story_ar' => 'أسرة فقدت معيلها وتسكن غرفة واحدة بالإيجار.']);

    expect($case->refresh()->story_ar)->toBe('أسرة فقدت معيلها وتسكن غرفة واحدة بالإيجار.');
});

it('does not sign it by writing it, because those are two decisions', function () {
    $case = publishedCase($this->region);

    classify($case, ['story_ar' => 'أسرة فقدت معيلها.']);

    expect($case->refresh()->story_approved_at)->toBeNull()
        // And nothing of it has reached a donor.
        ->and(maskedOf($case->refresh())['story_ar'])->toBeNull();
});

it('offers him the approve button once there is something to approve', function () {
    $case = publishedCase($this->region);

    // Before: nothing written, so nothing to sign and no button.
    Livewire\Livewire::test(ViewBeneficiary::class, ['record' => $case->getRouteKey()])
        ->assertActionHidden('approveStory');

    classify($case, ['story_ar' => 'أسرة فقدت معيلها.']);

    Livewire\Livewire::test(ViewBeneficiary::class, ['record' => $case->fresh()->getRouteKey()])
        ->assertActionVisible('approveStory')
        ->callAction('approveStory');

    $case->refresh();

    expect($case->story_approved_at)->not->toBeNull()
        ->and($case->story_approved_by)->toBe($this->director->id)
        ->and(maskedOf($case)['story_ar'])->toBe('أسرة فقدت معيلها.');
});

it('withdraws the signature when he rewrites the words through this same modal', function () {
    $case = publishedCase($this->region);

    classify($case, ['story_ar' => 'النصّ الأول.']);

    Livewire\Livewire::test(ViewBeneficiary::class, ['record' => $case->fresh()->getRouteKey()])
        ->callAction('approveStory');

    expect($case->refresh()->story_approved_at)->not->toBeNull();

    classify($case->refresh(), ['story_ar' => 'النصّ الثاني.']);

    expect($case->refresh()->story_approved_at)->toBeNull()
        ->and($case->story_approved_by)->toBeNull()
        ->and(maskedOf($case)['story_ar'])->toBeNull();
});

it('keeps the classification and the note it already saved', function () {
    $case = publishedCase($this->region);

    classify($case, [
        'zakat_category' => 'needy',
        'case_classifications' => ['orphan'],
        'story_ar' => 'شرحٌ للمتبرع.',
        'management_note_ar' => 'ملاحظةٌ داخلية.',
    ]);

    $case->refresh();

    expect($case->zakat_category)->toBe('needy')
        ->and($case->case_classifications)->toBe(['orphan'])
        ->and($case->story_ar)->toBe('شرحٌ للمتبرع.')
        ->and($case->management_note_ar)->toBe('ملاحظةٌ داخلية.');
});

it('fills the box with what is already on the file, so a rewrite starts from it', function () {
    $case = publishedCase($this->region);
    $case->forceFill(['story_ar' => 'ما كتبه المندوب.'])->save();

    Livewire\Livewire::test(ViewBeneficiary::class, ['record' => $case->getRouteKey()])
        ->mountAction('classify')
        ->assertActionDataSet(['story_ar' => 'ما كتبه المندوب.']);
});

it('opens the box to nobody who may not approve a file', function () {
    $case = publishedCase($this->region);
    $delegate = userWithRole('delegate', ['region_id' => $this->region->id]);

    $this->actingAs($delegate);

    Livewire\Livewire::actingAs($delegate)
        ->test(ViewBeneficiary::class, ['record' => $case->getRouteKey()])
        ->assertActionHidden('classify');

    expect($case->fresh()->story_ar)->toBeNull();
});
