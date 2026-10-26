<?php

use App\Filament\Resources\BeneficiaryResource\Pages\ListBeneficiaries;
use App\Models\Beneficiary;
use Filament\Facades\Filament;

/*
 | T-163 — «القصة غير ظاهرة, لازم تكون ظاهرة بالصفحة», 30 October.
 |
 | It was written and never signed. A story reaches the open internet only
 | once an account that may approve a file puts its name to it (T-106) — the
 | one guard between a paragraph about a household and a stranger reading it,
 | and it stays. What was missing was any way to see which files were waiting:
 | the button lives inside the file, and nothing on the list said which files
 | had one.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    passTwoFactor();
});

/** A published family whose story is written and unsigned. */
function familyWithAnUnsignedStory($region): Beneficiary
{
    $case = publishedCase($region);

    $case->forceFill([
        'story_ar' => 'أسرة فقدت معيلها وتسكن بالإيجار.',
        'story_approved_at' => null,
        'story_approved_by' => null,
    ])->save();

    return $case->refresh();
}

it('does not publish a story nobody signed', function () {
    $case = familyWithAnUnsignedStory($this->region);

    expect(maskedOf($case)['story_ar'])->toBeNull();

    $this->get(route('opportunity', $case->file_number))
        ->assertOk()
        ->assertDontSee('أسرة فقدت معيلها');
});

it('says on the list which files are waiting for a signature', function () {
    $waiting = familyWithAnUnsignedStory($this->region);
    $signed = publishedCase($this->region);
    $signed->forceFill(['story_ar' => 'قصة موقّعة', 'story_approved_at' => now()])->save();

    $officer = userWithRole('case_officer', ['region_id' => $this->region->id]);
    $this->actingAs($officer);

    Livewire\Livewire::actingAs($officer)
        ->test(ListBeneficiaries::class)
        ->filterTable('story_awaiting')
        ->assertCanSeeTableRecords([$waiting])
        ->assertCanNotSeeTableRecords([$signed]);
});

it('signs them in one go, and the story is on the page straight after', function () {
    $case = familyWithAnUnsignedStory($this->region);

    $director = userWithRole('executive_director', ['region_id' => $this->region->id]);
    $this->actingAs($director);

    Livewire\Livewire::actingAs($director)
        ->test(ListBeneficiaries::class)
        ->callTableBulkAction('approveStories', [$case]);

    $case->refresh();

    expect($case->story_approved_at)->not->toBeNull()
        ->and($case->story_approved_by)->toBe($director->id);

    $this->get(route('opportunity', $case->file_number))
        ->assertOk()
        ->assertSee('أسرة فقدت معيلها');
});

it('offers the signature to nobody who may not approve a file', function () {
    $case = familyWithAnUnsignedStory($this->region);

    $delegate = userWithRole('delegate', ['region_id' => $this->region->id]);
    $this->actingAs($delegate);

    Livewire\Livewire::actingAs($delegate)
        ->test(ListBeneficiaries::class)
        ->assertTableBulkActionHidden('approveStories');

    expect($case->fresh()->story_approved_at)->toBeNull();
});

it('takes the signature off again the moment the words change', function () {
    $case = familyWithAnUnsignedStory($this->region);
    $approver = userWithRole('executive_director', ['region_id' => $this->region->id]);
    $case->forceFill(['story_approved_at' => now(), 'story_approved_by' => $approver->id])->save();

    $case->update(['story_ar' => 'نصّ آخر']);

    expect($case->fresh()->story_approved_at)->toBeNull();
});
