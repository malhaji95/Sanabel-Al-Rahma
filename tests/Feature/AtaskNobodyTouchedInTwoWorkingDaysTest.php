<?php

/*
 | T-240 — «نعتمد مدة يومين عمل بدل 3 أيام، مع إمكانية تعديل عدد الأيام من
 | لوحة الإعدادات دون الرجوع للمبرمج. تبدأ المدة من وقت إسناد المهمة
 | لمسؤول البيانات. إذا انتهت المدة دون معالجة، يصل تنبيه لمسؤول البيانات،
 | وإشعار للمدير التنفيذي ونائبه … وتسجيل وقت التنبيه والإجراءات المتخذة في
 | سجل النظام، وإيقاف التنبيه تلقائيًا عند إنجاز المهمة», 3 November.
 */

use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\ChangeRequest;
use App\Models\Region;
use App\Models\Setting;
use App\Services\CaseService;
use App\Services\OverdueTaskAlerts;
use Illuminate\Support\Carbon;

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->supervisor = userWithRole('data_supervisor');
    $this->director = userWithRole('executive_director');
    $this->deputy = userWithRole('deputy_executive_director');
});

/** A pending task that landed at a given moment. */
function taskThatLandedAt(Region $region, Carbon $at): ChangeRequest
{
    $case = publishedCase($region);

    $request = app(CaseService::class)->requestChange(
        $case,
        userWithRole('data_officer'),
        ['first_name' => 'اسم مصحَّح'],
        'تصحيح اسم',
    );

    $request->forceFill(['created_at' => $at])->save();

    return $request->refresh();
}

it('reads the working days off the settings, not off the code', function () {
    expect(OverdueTaskAlerts::dueWorkingDays())->toBe(2);

    Setting::put('data_task_due_working_days', 5);

    expect(OverdueTaskAlerts::dueWorkingDays())->toBe(5);
});

it('counts working days and steps over the association’s weekend', function () {
    // Thursday afternoon, with Friday and Saturday off: two working days
    // lands on Monday, not on Saturday.
    $thursday = Carbon::parse('2026-11-05 15:00');

    expect($thursday->dayOfWeek)->toBe(Carbon::THURSDAY)
        ->and(app(OverdueTaskAlerts::class)->dueAt($thursday)->toDateString())
        ->toBe('2026-11-09');
});

it('follows the association’s own week when they change it', function () {
    Setting::put('weekend_days', ['sunday']);

    // Thursday plus two working days is Saturday now, because Friday works
    // and only Sunday does not.
    expect(app(OverdueTaskAlerts::class)->dueAt(Carbon::parse('2026-11-05 15:00'))->toDateString())
        ->toBe('2026-11-07');
});

it('says nothing while the window is still open', function () {
    taskThatLandedAt($this->region, now()->subHours(3));

    expect(app(OverdueTaskAlerts::class)->run())->toBe(0)
        ->and(AppNotification::where('template_key', 'data_task_overdue')->count())->toBe(0);
});

it('alerts the desk that holds the task and tells the desk above it', function () {
    $task = taskThatLandedAt($this->region, now()->subDays(10));

    expect(app(OverdueTaskAlerts::class)->run())->toBe(1);

    $owner = AppNotification::where('recipient_id', $this->supervisor->id)
        ->where('template_key', 'data_task_overdue')->first();

    expect($owner)->not->toBeNull()
        // Rule 10 — the task's own number and nothing of the household.
        ->and($owner->payload_json)->toBe(['id' => $task->id]);

    foreach ([$this->director, $this->deputy] as $above) {
        expect(AppNotification::where('recipient_id', $above->id)
            ->where('template_key', 'data_task_escalated')->exists())->toBeTrue();
    }
});

it('records the time of the alert and writes it to the system log', function () {
    $task = taskThatLandedAt($this->region, now()->subDays(10));

    app(OverdueTaskAlerts::class)->run();

    $entry = AuditLog::where('action', 'data_task_overdue')
        ->where('entity_id', $task->id)->first();

    expect($task->refresh()->alerted_at)->not->toBeNull()
        ->and($entry)->not->toBeNull()
        ->and($entry->after_json['due_working_days'])->toBe(2);
});

it('never sends the same alert twice', function () {
    taskThatLandedAt($this->region, now()->subDays(10));

    expect(app(OverdueTaskAlerts::class)->run())->toBe(1)
        ->and(app(OverdueTaskAlerts::class)->run())->toBe(0)
        ->and(AppNotification::where('template_key', 'data_task_overdue')
            ->where('recipient_id', $this->supervisor->id)->count())->toBe(2); // in_app + email
});

it('stops of its own accord when the task is done', function () {
    $task = taskThatLandedAt($this->region, now()->subDays(10));

    app(CaseService::class)->approveChange($task, $this->supervisor);

    // Nothing was switched off: a decided request is simply not in the queue.
    expect(app(OverdueTaskAlerts::class)->run())->toBe(0)
        ->and($task->refresh()->alerted_at)->toBeNull();
});

it('leaves the row unstamped while the association has named nobody', function () {
    Setting::put('data_task_alert_roles', []);
    Setting::put('data_task_escalation_roles', []);

    $task = taskThatLandedAt($this->region, now()->subDays(10));

    // An alert addressed to no one is worse than none (T-95), and the row
    // waits rather than being passed over for good.
    expect(app(OverdueTaskAlerts::class)->run())->toBe(0)
        ->and($task->refresh()->alerted_at)->toBeNull()
        ->and(AppNotification::where('template_key', 'data_task_overdue')->count())->toBe(0);

    Setting::put('data_task_alert_roles', ['data_supervisor']);

    expect(app(OverdueTaskAlerts::class)->run())->toBe(1);
});

it('is run by the scheduled command', function () {
    taskThatLandedAt($this->region, now()->subDays(10));

    $this->artisan('sanabel:chase-tasks')->assertSuccessful();

    expect(AppNotification::where('template_key', 'data_task_overdue')->exists())->toBeTrue();
});
