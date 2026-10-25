<?php

use App\Filament\Association\Resources\CaseResource;
use App\Models\AppNotification;
use App\Models\Beneficiary;
use App\Models\Region;
use App\Models\User;
use App\Services\CaseService;
use Filament\Facades\Filament;

/*
 | T-144 — the association's notes 16 and 17 of 28 October: «الجمعية لازم
 | دورها من التسجيل كفقير وبعدها يذهب الى المندوب الميداني» and «هذي الجمعية
 | ليش تظهر اسماء مستفيدين وهو ماضاف شي؟ واذا كان خاص فيه ليش مايفتح للاطلاع
 | والتعديل؟».
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->village = placeIn($this->region, 'village');
    Filament::setCurrentPanel(Filament::getPanel('association'));
    passTwoFactor();
});

/** A partner association account, attached to one locality. */
function partner(): User
{
    return userWithRole('association', ['region_id' => test()->village->id]);
}

it('registers a household from the association own screen', function () {
    $association = partner();

    Livewire\Livewire::actingAs($association)
        ->test(CaseResource\Pages\CreateCase::class)
        ->fillForm([
            'first_name' => 'أحمد',
            'father_name' => 'محمد',
            'family_name' => 'العلي',
            'national_id_encrypted' => '12345678901',
            'gender' => 'male',
            'region_area_id' => $this->village->parent_id,
            'region_id' => $this->village->id,
            'support_type' => array_key_first(__('sanabel.masked.need_type')),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $case = Beneficiary::withoutGlobalScopes()->latest('id')->first();

    expect($case)->not->toBeNull()
        ->and($case->created_by)->toBe($association->id)
        ->and($case->source)->toBe('association')
        // A draft: the association registers and signs nothing (16 Oct).
        ->and($case->status)->toBe('draft');
});

it('hands the finished registration to the delegates of that place', function () {
    $association = partner();
    $delegate = userWithRole('delegate', ['region_id' => $this->village->id]);
    $elsewhere = userWithRole('delegate', ['region_id' => Region::create([
        'name_ar' => 'قرية بعيدة', 'type' => 'village',
        'parent_id' => $this->village->parent_id, 'is_active' => true,
    ])->id]);

    $case = familyOf($this->region);
    $case->forceFill([
        'created_by' => $association->id,
        'region_id' => $this->village->id,
        'status' => 'draft',
        'privacy_policy_seen' => true,
        'privacy_consent' => true,
    ])->save();

    $this->actingAs($association);

    app(CaseService::class)->sendToField($case, $association);

    expect($case->refresh()->status)->toBe('pending_visit')
        ->and(AppNotification::where('recipient_id', $delegate->id)
            ->where('template_key', 'case_awaiting_visit')->exists())->toBeTrue()
        // Not a delegate of another village: a notification nobody can act on
        // teaches people to stop reading notifications.
        ->and(AppNotification::where('recipient_id', $elsewhere->id)->exists())->toBeFalse();
});

it('refuses the hand-over of a file that has not been consented to', function () {
    // T-74's gate, which this does not relax.
    $association = partner();

    $case = familyOf($this->region);
    $case->forceFill(['created_by' => $association->id, 'status' => 'draft'])->save();

    $this->actingAs($association);

    expect(fn () => app(CaseService::class)->sendToField($case, $association))
        ->toThrow(RuntimeException::class);

    expect($case->refresh()->status)->toBe('draft');
})->group('GOV-01');

it('sends nothing that is no longer a draft', function () {
    $association = partner();

    $case = familyOf($this->region);
    $case->forceFill(['created_by' => $association->id, 'status' => 'verified'])->save();

    $this->actingAs($association);

    expect(fn () => app(CaseService::class)->sendToField($case, $association))
        ->toThrow(RuntimeException::class);
});

it('shows the association its own files and not another body files', function () {
    $association = partner();

    $ours = familyOf($this->region);
    $ours->forceFill(['created_by' => $association->id, 'region_id' => $this->village->id])->save();

    // Registered by a delegate in the same village: the association never
    // touched it, and before T-144 it read the whole file, name and all.
    $theirs = familyOf($this->region);
    $theirs->forceFill([
        'created_by' => userWithRole('delegate', ['region_id' => $this->village->id])->id,
        'region_id' => $this->village->id,
    ])->save();

    $this->actingAs($association);

    $visible = CaseResource::getEloquentQuery()->pluck('id');

    expect($visible)->toContain($ours->id)
        ->and($visible)->not->toContain($theirs->id)
        // And the policy refuses the file itself, not only the list.
        ->and($association->can('view', $theirs))->toBeFalse()
        ->and($association->can('view', $ours))->toBeTrue();
})->group('AUTH-02');

it('opens its own file for reading and for editing while it is a draft', function () {
    $association = partner();

    $case = familyOf($this->region);
    $case->forceFill([
        'created_by' => $association->id,
        'region_id' => $this->village->id,
        'status' => 'draft',
    ])->save();

    expect($association->can('view', $case))->toBeTrue()
        ->and($association->can('update', $case))->toBeTrue()
        ->and($association->can('sendToField', $case))->toBeTrue();

    Livewire\Livewire::actingAs($association)
        ->test(CaseResource\Pages\EditCase::class, ['record' => $case->getRouteKey()])
        ->assertSuccessful()
        ->assertActionVisible('sendToField');
})->group('AUTH-01');
