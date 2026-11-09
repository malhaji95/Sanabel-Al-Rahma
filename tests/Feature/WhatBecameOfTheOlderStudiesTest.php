<?php

use App\Filament\Resources\BeneficiaryResource\Pages\ViewBeneficiary;
use App\Filament\Resources\BeneficiaryResource\RelationManagers\AssessmentsRelationManager;
use App\Models\RegionRate;
use App\Services\AssessmentService;
use Filament\Facades\Filament;

/*
 | T-278 — «توضيح كيف تُحفظ الدراسات السابقة والإصدارات والتعديلات ومن
 | اعتمدها», 9 November.
 |
 | All of it was stored from the first migration and none of it was on a
 | screen: the file drew the study in force and the older rows sat in the
 | table unread. These tests are about the table that reads them.
 */
beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
});

it('keeps the older study instead of overwriting it', function () {
    $case = publishedCase($this->region, adults: 2, children: 3);
    $first = $case->currentAssessment();

    app(AssessmentService::class)->create($case, status: 'approved');
    $second = $case->refresh()->currentAssessment();

    expect($second->id)->not->toBe($first->id)
        ->and($first->fresh()->status)->toBe('superseded')
        ->and($first->fresh()->deleted_at)->toBeNull()
        ->and($case->assessments()->count())->toBe(2);
});

it('says of each study who computed it and who approved it', function () {
    $researcher = userWithRole('researcher');
    $this->actingAs($researcher);

    $case = publishedCase($this->region);
    $assessment = $case->currentAssessment();

    $approver = userWithRole('executive_director');
    $assessment->update(['approved_by' => $approver->id, 'approved_at' => now()]);

    expect($assessment->fresh()->creator?->id)->toBe($researcher->id)
        ->and($assessment->fresh()->approver?->id)->toBe($approver->id);
});

it('lists every study on the file, newest first, with the two names', function () {
    $case = publishedCase($this->region, adults: 2, children: 3);
    app(AssessmentService::class)->create($case, status: 'approved');

    $approver = userWithRole('executive_director');
    $case->refresh()->currentAssessment()
        ->update(['approved_by' => $approver->id, 'approved_at' => now()]);

    passTwoFactor();
    $this->actingAs(userWithRole('executive_director'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire\Livewire::test(AssessmentsRelationManager::class, [
        'ownerRecord' => $case->refresh(),
        'pageClass' => ViewBeneficiary::class,
    ])
        ->assertCanSeeTableRecords($case->assessments()->get())
        ->assertSee(__('sanabel.assessment_history.statuses.superseded'))
        ->assertSee(__('sanabel.assessment_history.statuses.approved'))
        ->assertSee($approver->name);
});

it('explains an older study with its own values, not those of the one in force', function () {
    $case = publishedCase($this->region, adults: 2, children: 3);
    $old = $case->currentAssessment();

    // The association raises the child rate and recomputes: the new study
    // costs more, and the old one must go on explaining itself with the
    // figure it was computed from (rule 8).
    RegionRate::create([
        'region_id' => $this->region->id,
        'person_class' => 'child',
        'amount' => 9000,
        'currency' => config('sanabel.currency'),
        'effective_from' => now()->subDay(),
        'version' => 99,
    ]);

    app(AssessmentService::class)->create($case, status: 'approved');
    $new = $case->refresh()->currentAssessment();

    $unit = fn ($a) => collect($a->needBreakdown()['need'])->firstWhere('key', 'child')['unit'];

    expect($unit($old->fresh()))->toBe(2000)
        ->and($unit($new))->toBe(9000)
        ->and($new->monthly_need)->toBeGreaterThan($old->monthly_need);
});

it('is a table nobody can write a study into', function () {
    $case = publishedCase($this->region);

    passTwoFactor();
    $this->actingAs(userWithRole('executive_director'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $manager = Livewire\Livewire::test(AssessmentsRelationManager::class, [
        'ownerRecord' => $case,
        'pageClass' => ViewBeneficiary::class,
    ]);

    $manager->assertTableActionDoesNotExist('edit')
        ->assertTableActionDoesNotExist('delete');

    $table = $manager->instance()->getTable();

    // No create button, and the only bulk action is T-281's comparison, which
    // reads two studies and writes nothing.
    expect($table->getHeaderActions())->toBe([])
        ->and(collect($table->getBulkActions())->map(fn ($a) => $a->getName())->all())
        ->toBe(['compare']);
});

it('draws the older study its own explanation, on the study itself', function () {
    $case = publishedCase($this->region, adults: 2, children: 3);
    $old = $case->currentAssessment();

    RegionRate::create([
        'region_id' => $this->region->id,
        'person_class' => 'child',
        'amount' => 9000,
        'currency' => config('sanabel.currency'),
        'effective_from' => now()->subDay(),
        'version' => 99,
    ]);

    app(AssessmentService::class)->create($case, status: 'approved');

    passTwoFactor();
    $this->actingAs(userWithRole('executive_director'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    // The explanation opened from the superseded row is that row's own: the
    // two views read the record they are given, so the history screen hands
    // them one study and the family's file hands them the one in force.
    Livewire\Livewire::test(AssessmentsRelationManager::class, [
        'ownerRecord' => $case->refresh(),
        'pageClass' => ViewBeneficiary::class,
    ])
        ->mountTableAction('view', $old->getKey())
        ->assertSee(__('sanabel.need_breakdown.title'))
        ->assertSee(__('sanabel.score_breakdown.title'))
        ->assertSee(number_format($old->monthly_need))
        ->assertDontSee(number_format($case->refresh()->currentAssessment()->monthly_need));
});
