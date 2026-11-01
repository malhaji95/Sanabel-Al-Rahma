<?php

use App\Filament\Resources\BeneficiaryResource\Pages\ListBeneficiaries;
use App\Filament\Widgets\OverviewStats;
use App\Models\Beneficiary;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Collection;
use Livewire\Livewire;

/*
 | T-211 — «هون هذه الحالة عم بيعطيني إنه منشور, ولكن هو مش منشور — هو
 | بالأصل قاعد ينتظر القصة. فهذي لازم يبيّنها, أو ننقلها على الصفحة الرئيسية
 | اللي عند المدير العام: اعتماد القصة لازم تكون موجودة هون», 30 October.
 |
 | The column that was supposed to say so (T-163) caught one of the two ways
 | a file can be short of a story — written and unsigned — and left the other
 | blank. So a published file nobody has written a story for looked exactly
 | like one whose story is signed and live: an empty cell beside «منشور».
 |
 | «منشور» stays true: the family is on the donor's list. What it does not
 | say, and now says underneath, is that the paragraph explaining them is
 | still owed.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    passTwoFactor();
});

/** A published file in whatever state of story. */
function fileWithStory(?string $story, bool $approved = false): Beneficiary
{
    $case = publishedCase(test()->region);

    $case->forceFill([
        'story_ar' => $story,
        'story_approved_by' => $approved ? null : null,
        'story_approved_at' => $approved ? now() : null,
    ])->save();

    return $case->refresh();
}

/** The dashboard cards an account is shown, by their label. */
function statsFor(User $user): Collection
{
    test()->actingAs($user);

    $method = new ReflectionMethod(OverviewStats::class, 'getStats');
    $method->setAccessible(true);

    return collect($method->invoke(new OverviewStats))
        ->mapWithKeys(fn ($stat) => [strip_tags((string) $stat->getLabel()) => $stat]);
}

/*
 | The words are asserted off the column's own state rather than off the page
 | text: the filter's dropdown lists all three of them on every render, so
 | «the page does not contain this word» can never be true and would be a
 | test that only ever looked like one.
 */
it('says on the column that a published file has no story written', function () {
    $case = fileWithStory(null);

    Livewire::actingAs(userWithRole('executive_director'))
        ->test(ListBeneficiaries::class)
        ->assertCanSeeTableRecords([$case])
        ->assertTableColumnStateSet('story_approved_at', __('sanabel.beneficiary.story_missing'), $case);
});

it('says the other thing when the story is written and nobody has signed it', function () {
    $case = fileWithStory('أسرة فقدت معيلها وتسكن غرفة واحدة.');

    Livewire::actingAs(userWithRole('executive_director'))
        ->test(ListBeneficiaries::class)
        ->assertTableColumnStateSet('story_approved_at', __('sanabel.beneficiary.story_awaiting'), $case);
});

it('says neither once the story is signed, because nothing is owed', function () {
    $case = fileWithStory('أسرة فقدت معيلها وتسكن غرفة واحدة.', approved: true);

    Livewire::actingAs(userWithRole('executive_director'))
        ->test(ListBeneficiaries::class)
        ->assertTableColumnStateSet('story_approved_at', null, $case);
});

it('prints the line under the status badge, which is the thing it explains', function () {
    $missing = fileWithStory(null);

    // The badge keeps saying «منشور» — that is true, the family is on the
    // donor's list — and the warning line underneath says what is still owed.
    Livewire::actingAs(userWithRole('executive_director'))
        ->test(ListBeneficiaries::class)
        ->assertCanSeeTableRecords([$missing])
        ->assertSeeHtml('text-warning-600 dark:text-warning-400">'.__('sanabel.beneficiary.story_missing').'</span>');
});

it('prints no such line once the story is signed', function () {
    $signed = fileWithStory('قصة موقّعة.', approved: true);

    Livewire::actingAs(userWithRole('executive_director'))
        ->test(ListBeneficiaries::class)
        ->assertCanSeeTableRecords([$signed])
        ->assertDontSeeHtml('text-warning-600 dark:text-warning-400">'.__('sanabel.beneficiary.story_missing').'</span>')
        ->assertDontSeeHtml('text-warning-600 dark:text-warning-400">'.__('sanabel.beneficiary.story_awaiting').'</span>');
});

it('lets the reader ask for each of the three states on its own', function () {
    $missing = fileWithStory(null);
    $awaiting = fileWithStory('قصة مكتوبة.');
    $signed = fileWithStory('قصة مكتوبة ومعتمدة.', approved: true);

    $list = fn (string $value) => Livewire::actingAs(userWithRole('executive_director'))
        ->test(ListBeneficiaries::class)
        ->filterTable('story_state', $value);

    $list('missing')->assertCanSeeTableRecords([$missing])
        ->assertCanNotSeeTableRecords([$awaiting, $signed]);

    $list('awaiting')->assertCanSeeTableRecords([$awaiting])
        ->assertCanNotSeeTableRecords([$missing, $signed]);

    $list('approved')->assertCanSeeTableRecords([$signed])
        ->assertCanNotSeeTableRecords([$missing, $awaiting]);
});

it('asks for both ways of being short of one in a single question', function () {
    $missing = fileWithStory(null);
    $awaiting = fileWithStory('قصة مكتوبة.');
    $signed = fileWithStory('قصة مكتوبة ومعتمدة.', approved: true);

    Livewire::actingAs(userWithRole('executive_director'))
        ->test(ListBeneficiaries::class)
        ->filterTable('story_state', 'unapproved')
        ->assertCanSeeTableRecords([$missing, $awaiting])
        ->assertCanNotSeeTableRecords([$signed]);
});

it('counts them on the director’s own front page, which is where he went looking', function () {
    fileWithStory(null);
    fileWithStory('قصة مكتوبة.');
    fileWithStory('قصة مكتوبة ومعتمدة.', approved: true);

    $card = statsFor(userWithRole('executive_director'))[__('sanabel.dashboard.published_without_story')];

    expect($card->getValue())->toBe(2)
        // And the card opens the list already asking the same question.
        ->and($card->getUrl())->toContain('story_state');
});

it('treats an empty string as no story at all, because the textarea writes one', function () {
    $case = fileWithStory('');

    Livewire::actingAs(userWithRole('executive_director'))
        ->test(ListBeneficiaries::class)
        ->filterTable('story_state', 'missing')
        ->assertCanSeeTableRecords([$case]);
});

it('leaves an anonymised file out of it, which has no story on purpose', function () {
    $case = fileWithStory(null);
    $case->forceFill(['anonymised_at' => now()])->save();

    Livewire::actingAs(userWithRole('executive_director'))
        ->test(ListBeneficiaries::class)
        ->filterTable('story_state', 'unapproved')
        ->assertCanNotSeeTableRecords([$case->refresh()]);

    expect(statsFor(userWithRole('executive_director'))[__('sanabel.dashboard.published_without_story')]->getValue())
        ->toBe(0);
});
