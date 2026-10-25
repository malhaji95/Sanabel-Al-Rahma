<?php

use App\Filament\Pages\ApprovalFollowUp;
use App\Filament\Resources\BeneficiaryResource\Pages\EditBeneficiary;
use App\Models\AppNotification;
use App\Services\CaseService;
use App\Services\NotificationService;
use Filament\Facades\Filament;

/*
 | T-125 — the association's notes 9 and 10 of 26 October.
 |
 | 9. «المشرف دخل وعطاه ملاحظات بعد المندوب, كيف بترجع الحالة للمندوب؟» There
 |    was no answer. A supervisor could endorse a file or leave it sitting;
 |    rejection is the director's word and means the family was refused, not
 |    that a page of the form is missing.
 |
 | 10. «يجب ان تظهر كم حالة متأخرة للمندوب … ولما يفوت مشرف المنطقة يقدر يشوف
 |     الحالات لكل مندوب ويشوف وين التأخر». The dashboard counted the queue
 |     and said nothing about whose it was.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    passTwoFactor();
});

it('sends a file back to the field with the note that sent it', function () {
    $delegate = userWithRole('delegate', ['region_id' => placeIn($this->region, 'village')->id]);
    $supervisor = userWithRole('area_supervisor', ['region_id' => $this->region->id]);

    $case = familyOf($this->region);
    $case->forceFill([
        'created_by' => $delegate->id,
        'status' => 'verified',
        'field_verified_by' => $delegate->id,
        'field_verified_at' => now()->subDay(),
    ])->save();

    app(CaseService::class)->returnToField($case, $supervisor, 'ينقص إثبات الإيجار وصورة الهوية.');

    $case->refresh();

    expect($case->status)->toBe('pending_visit')
        ->and($case->reject_reason_ar)->toBe('ينقص إثبات الإيجار وصورة الهوية.')
        // The sign-off below the step is given back: whoever picks the file
        // up next signs it themselves.
        ->and($case->field_verified_by)->toBeNull()
        ->and($case->field_verified_at)->toBeNull();

    // And the account that opened it is told, without the note travelling in
    // the notification body (rule 10).
    $notification = AppNotification::where('recipient_id', $delegate->id)
        ->where('template_key', 'case_returned')
        ->latest('id')
        ->first();

    expect($notification)->not->toBeNull();

    $rendered = app(NotificationService::class)->render($notification);

    expect($rendered['body'])->toContain($case->file_number)
        ->and($rendered['body'])->not->toContain('الإيجار')
        ->and($notification->payload_json)->not->toHaveKey('reason');
});

it('refuses to send a file back without saying what it lacks', function () {
    $supervisor = userWithRole('area_supervisor', ['region_id' => $this->region->id]);

    $case = familyOf($this->region);
    $case->forceFill(['status' => 'verified'])->save();

    expect(fn () => app(CaseService::class)->returnToField($case, $supervisor, '   '))
        ->toThrow(RuntimeException::class);

    expect($case->refresh()->status)->toBe('verified');
});

it('is not a way to undo an approval, nor a word about the family', function () {
    /*
     | Only a file in review goes back. An approved or published one is a
     | decision, and undoing a decision is its own action with its own key.
     */
    $supervisor = userWithRole('area_supervisor', ['region_id' => $this->region->id]);

    foreach (['approved', 'published', 'draft'] as $status) {
        $case = familyOf($this->region);
        $case->forceFill(['status' => $status])->save();

        expect(fn () => app(CaseService::class)->returnToField($case, $supervisor, 'ملاحظة'))
            ->toThrow(RuntimeException::class);
    }
});

it('offers the button to the people who read a file before it is approved', function () {
    $case = familyOf($this->region);
    $case->forceFill(['status' => 'verified'])->save();

    expect(userWithRole('area_supervisor', ['region_id' => $this->region->id])->can('returnToField', $case))->toBeTrue()
        ->and(userWithRole('executive_director')->can('returnToField', $case))->toBeTrue()
        // Not an account with no part in the review.
        ->and(userWithRole('donor')->can('returnToField', $case))->toBeFalse()
        ->and(userWithRole('council')->can('returnToField', $case))->toBeFalse();
})->group('AUTH-01');

it('shows the action on the file itself', function () {
    $supervisor = userWithRole('area_supervisor', ['region_id' => $this->region->id]);

    $case = familyOf($this->region);
    $case->forceFill(['status' => 'verified'])->save();

    Livewire\Livewire::actingAs($supervisor)
        ->test(EditBeneficiary::class, ['record' => $case->getRouteKey()])
        ->assertActionVisible('returnToField');
});

it('breaks the delegate queue down by who opened each file and how long it has waited', function () {
    $delegate = userWithRole('delegate', ['region_id' => placeIn($this->region, 'village')->id]);

    foreach ([2, 30, 60, 90] as $hoursAgo) {
        $case = familyOf($this->region);
        $case->forceFill(['created_by' => $delegate->id, 'status' => 'pending_visit'])->save();
        $case->forceFill(['created_at' => now()->subHours($hoursAgo)])->saveQuietly();
    }

    $rows = collect((new ApprovalFollowUp)->awaitingDelegate())
        ->firstWhere('label', $delegate->name);

    expect($rows)->not->toBeNull()
        ->and($rows['total'])->toBe(4)
        // One between twenty-four and forty-eight hours, two past forty-eight.
        ->and($rows['late'])->toBe(1)
        ->and($rows['very_late'])->toBe(2)
        ->and($rows['oldest_hours'])->toBeGreaterThanOrEqual(89);
});

it('breaks the supervisor queue down by area, and names who covers it', function () {
    $area = placeIn($this->region, 'area');
    $supervisor = userWithRole('area_supervisor', ['region_id' => $area->id]);

    $case = familyOf($this->region);
    $case->forceFill([
        'status' => 'verified',
        'field_verified_at' => now()->subHours(72),
    ])->save();

    $rows = collect((new ApprovalFollowUp)->awaitingSupervisor());

    expect($rows)->not->toBeEmpty();

    $row = $rows->first();

    expect($row['total'])->toBe(1)
        ->and($row['very_late'])->toBe(1)
        ->and($row['oldest_hours'])->toBeGreaterThanOrEqual(71)
        // The telephone call this screen exists to make.
        ->and($row['people'])->toContain($supervisor->name);
});

it('opens to the accounts that answer for the queue, and not to a delegate', function () {
    $this->actingAs(userWithRole('executive_director'));
    expect(ApprovalFollowUp::canAccess())->toBeTrue();

    $this->actingAs(userWithRole('area_supervisor', ['region_id' => $this->region->id]));
    expect(ApprovalFollowUp::canAccess())->toBeTrue();

    $this->actingAs(userWithRole('donor'));
    expect(ApprovalFollowUp::canAccess())->toBeFalse();
})->group('AUTH-01');
