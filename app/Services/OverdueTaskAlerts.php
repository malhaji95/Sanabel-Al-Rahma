<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\ChangeRequest;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * T-240 — a task that sat untouched past its working days.
 *
 * «نعتمد مدة يومين عمل بدل 3 أيام، مع إمكانية تعديل عدد الأيام من لوحة
 * الإعدادات دون الرجوع للمبرمج. تبدأ المدة من وقت إسناد المهمة لمسؤول
 * البيانات. إذا انتهت المدة دون معالجة، يصل تنبيه لمسؤول البيانات، وإشعار
 * للمدير التنفيذي ونائبه», 3 November.
 *
 * Three decisions of theirs, all of them data:
 *
 *   · how many working days — a number on the settings screen;
 *   · who is alerted, and who is told above them — two lists of roles, so
 *     «مدير عمليات» the day they create one is a line on a screen rather
 *     than a deploy. Roles and not addresses, for the reason T-95 gave:
 *     an address goes stale the day somebody leaves, a role follows
 *     whoever holds it;
 *   · which days are not working days — a list, because the week is the
 *     association's and not ours.
 *
 * And one of ours, stated rather than assumed: the clock starts when the
 * work reached the queue, because nothing in this system assigns a task to
 * a named person. The day they want a real hand-over, that timestamp is
 * the column this reads instead.
 *
 * The queue is pending change requests, which is مسؤول البيانات's own
 * task: `approve_change` is theirs and the data manager's. Any other queue
 * they name is another method beside `overdueChangeRequests()`.
 *
 * Rule 10 — an alert carries the request's id and nothing of the family.
 */
class OverdueTaskAlerts
{
    private const DAY_NUMBERS = [
        'sunday' => 0, 'monday' => 1, 'tuesday' => 2, 'wednesday' => 3,
        'thursday' => 4, 'friday' => 5, 'saturday' => 6,
    ];

    public function __construct(private readonly NotificationService $notifications) {}

    public static function dueWorkingDays(): int
    {
        return (int) Setting::value(
            'data_task_due_working_days',
            config('sanabel.setting_defaults.data_task_due_working_days')
        );
    }

    /** The days the association does not work, as day-of-week numbers. */
    public static function weekend(): array
    {
        $names = (array) Setting::value(
            'weekend_days',
            config('sanabel.setting_defaults.weekend_days')
        );

        return array_values(array_filter(array_map(
            fn ($name) => self::DAY_NUMBERS[strtolower(trim((string) $name))] ?? null,
            $names,
        ), fn ($number) => $number !== null));
    }

    /**
     * When a task that arrived at `$from` falls due.
     *
     * Working days, so a request that lands on a Thursday afternoon with a
     * two-day window is not late on Saturday morning. The count moves day by
     * day and only a working day counts, which is the whole arithmetic: a
     * week with every day in the weekend would never fall due, so that case
     * is refused by falling back to plain days.
     */
    public function dueAt(Carbon $from): Carbon
    {
        $weekend = static::weekend();
        $days = max(1, static::dueWorkingDays());
        $cursor = $from->copy();

        if (count($weekend) >= 7) {
            return $cursor->addDays($days);
        }

        $counted = 0;

        while ($counted < $days) {
            $cursor->addDay();

            if (! in_array($cursor->dayOfWeek, $weekend, true)) {
                $counted++;
            }
        }

        return $cursor;
    }

    /**
     * The requests that are past due and have not been alerted on.
     *
     * Pending only, which is «إيقاف التنبيه تلقائيًا عند إنجاز المهمة»: a
     * decided request leaves this query by itself, with no switch to throw.
     */
    public function overdueChangeRequests(): Collection
    {
        return ChangeRequest::query()
            ->where('status', 'pending')
            ->whereNull('alerted_at')
            ->get()
            ->filter(fn (ChangeRequest $request) => $request->created_at
                && $this->dueAt($request->created_at)->isPast());
    }

    /** Sends what is due, stamps it, and records it. Returns how many. */
    public function run(): int
    {
        $sent = 0;

        foreach ($this->overdueChangeRequests() as $request) {
            $owners = $this->recipients('data_task_alert_roles');
            $above = $this->recipients('data_task_escalation_roles');

            // Addressed to nobody is worse than not sent at all (T-95): the
            // row is left unstamped, so it is alerted the day they name
            // somebody rather than passed over for good.
            if ($owners === [] && $above === []) {
                continue;
            }

            foreach ($owners as $recipient) {
                $this->notifications->send($recipient, 'data_task_overdue', ['id' => $request->getKey()]);
            }

            foreach ($above as $recipient) {
                $this->notifications->send($recipient, 'data_task_escalated', ['id' => $request->getKey()]);
            }

            $request->forceFill(['alerted_at' => now()])->save();

            AuditLog::create([
                'actor_id' => null,
                'actor_role' => null,
                'action' => 'data_task_overdue',
                'entity_type' => ChangeRequest::class,
                'entity_id' => $request->getKey(),
                'after_json' => [
                    'due_working_days' => static::dueWorkingDays(),
                    'alerted' => count($owners),
                    'escalated' => count($above),
                ],
            ]);

            $sent++;
        }

        return $sent;
    }

    /** @return array<int, int> */
    private function recipients(string $settingKey): array
    {
        $roles = (array) Setting::value(
            $settingKey,
            config('sanabel.setting_defaults.'.$settingKey)
        );

        if ($roles === []) {
            return [];
        }

        return User::query()
            ->where('is_active', true)
            ->whereHas('role', fn ($query) => $query->whereIn('key', $roles))
            ->pluck('id')
            ->all();
    }
}
