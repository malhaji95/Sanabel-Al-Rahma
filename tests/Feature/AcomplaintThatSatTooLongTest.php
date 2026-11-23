<?php

use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Complaint;
use App\Models\Setting;
use App\Services\ComplaintEscalation;
use App\Services\ComplaintService;

/*
 | T-320 — phase two, item 3: «مهلة تصعيد».
 |
 | T-240 gave the data desk a working-day window and a morning that chases it.
 | A complaint had nothing of the sort: the follow-up screen shows it sitting
 | there, and whoever does not open that screen does not know it is sitting.
 */

beforeEach(function () {
    seedCore();
    $this->owner = userWithRole('case_officer');
    $this->director = userWithRole('executive_director');
    $this->escalation = app(ComplaintEscalation::class);

    // A week with no weekend, so the arithmetic under test is the window and
    // not the calendar — T-240's own weekend rules have their own tests.
    Setting::updateOrCreate(['key' => 'weekend_days'], ['value_json' => []]);
});

/** A ticket opened this many days ago and still with the desk. */
function waitingComplaint(int $daysAgo, array $overrides = []): Complaint
{
    $ticket = app(ComplaintService::class)->file(array_merge([
        'subject_ar' => 'لم يصلني ردّ',
        'body_ar' => 'مضى وقت ولم يصلني شيء.',
        'category' => 'service',
        'reporter_name_ar' => 'زائر',
        'reporter_phone' => '0912345678',
        'reporter_role' => 'visitor',
    ], $overrides));

    $ticket->forceFill(['created_at' => now()->subDays($daysAgo)])->saveQuietly();

    return $ticket->refresh();
}

it('finds a complaint past its window, and leaves a fresh one alone', function () {
    waitingComplaint(1);
    $late = waitingComplaint(9);

    $found = $this->escalation->overdue();

    expect($found->pluck('id')->all())->toBe([$late->id]);
});

it('wakes the desk that holds it and tells the desk above', function () {
    $late = waitingComplaint(9);
    $late->forceFill(['owner_id' => $this->owner->id, 'status' => 'assigned'])->save();

    expect($this->escalation->run())->toBe(1);

    $toOwner = AppNotification::where('recipient_id', $this->owner->id)
        ->where('template_key', 'complaint_overdue')->first();

    $toAbove = AppNotification::where('recipient_id', $this->director->id)
        ->where('template_key', 'complaint_escalated')->first();

    // The reference travels under the one key the service's allow-list
    // carries — rule 10's guard drops anything else, including a key of our
    // own invention.

    expect($toOwner)->not->toBeNull()
        ->and($toAbove)->not->toBeNull();

    // Rule 10 — the reference and nothing of the complaint.
    $written = json_encode([$toOwner->payload_json, $toAbove->payload_json], JSON_UNESCAPED_UNICODE);

    expect($written)->toContain($late->reference_no)
        ->and($written)->not->toContain('لم يصلني ردّ')
        ->and($written)->not->toContain('0912345678');
});

it('escalates a complaint nobody has picked up, which is what this is for', function () {
    $late = waitingComplaint(9);

    expect($late->owner_id)->toBeNull()
        ->and($late->status)->toBe('new');

    expect($this->escalation->run())->toBe(1);

    expect(AppNotification::where('recipient_id', $this->director->id)
        ->where('template_key', 'complaint_escalated')->exists())->toBeTrue();
});

it('chases each complaint once, not every morning after', function () {
    waitingComplaint(9);

    expect($this->escalation->run())->toBe(1)
        ->and($this->escalation->run())->toBe(0);

    // One notice per escalation account, and the queue writes a row per
    // channel, so the count is of complaints and not of rows.
    expect(AppNotification::where('template_key', 'complaint_escalated')
        ->where('recipient_id', $this->director->id)
        ->where('channel', 'in_app')
        ->count())->toBe(1);
});

it('leaves a ticket waiting on its own reporter out of it', function () {
    /*
     | `awaiting_reporter` is waiting on the person who opened it. Chasing the
     | desk for an answer it cannot give teaches the desk to ignore the chase.
     */
    $late = waitingComplaint(9);
    $late->forceFill(['status' => 'awaiting_reporter', 'owner_id' => $this->owner->id])->save();

    expect($this->escalation->overdue())->toBeEmpty()
        ->and($this->escalation->run())->toBe(0);
});

it('leaves a settled ticket alone', function () {
    $late = waitingComplaint(9);
    $late->forceFill(['status' => 'resolved', 'owner_id' => $this->owner->id])->save();

    expect($this->escalation->run())->toBe(0);
});

it('reads its window from the settings, and writes it into the record', function () {
    waitingComplaint(4);

    // Four days old, and the window is the default three: it is late.
    expect($this->escalation->overdue())->toHaveCount(1);

    Setting::updateOrCreate(['key' => 'complaint_due_working_days'], ['value_json' => 10]);

    expect(ComplaintEscalation::dueWorkingDays())->toBe(10)
        ->and($this->escalation->overdue())->toBeEmpty();

    Setting::updateOrCreate(['key' => 'complaint_due_working_days'], ['value_json' => 3]);

    $this->escalation->run();

    $row = AuditLog::where('action', 'complaint_overdue')->latest('id')->first();

    expect($row)->not->toBeNull()
        ->and($row->actor_id)->toBeNull()
        ->and($row->after_json['due_working_days'])->toBe(3)
        ->and($row->after_json['escalated'])->toBe(1);
});

it('leaves the row unstamped when there is nobody at all to tell', function () {
    /*
     | T-95 — addressed to nobody is worse than not sent. The ticket is chased
     | the day the association names somebody, rather than passed over for
     | good.
     */
    Setting::updateOrCreate(['key' => 'complaint_escalation_roles'], ['value_json' => []]);

    $late = waitingComplaint(9);

    expect($this->escalation->run())->toBe(0)
        ->and($late->refresh()->alerted_at)->toBeNull();

    Setting::updateOrCreate(['key' => 'complaint_escalation_roles'], ['value_json' => ['executive_director']]);

    expect($this->escalation->run())->toBe(1)
        ->and($late->refresh()->alerted_at)->not->toBeNull();
});

it('is run by the morning command', function () {
    waitingComplaint(9);

    $this->artisan('sanabel:chase-complaints')
        ->expectsOutputToContain('1')
        ->assertSuccessful();

    expect(AppNotification::where('template_key', 'complaint_escalated')
        ->where('recipient_id', $this->director->id)
        ->where('channel', 'in_app')
        ->count())->toBe(1);
});
