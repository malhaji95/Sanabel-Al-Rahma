<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Complaint;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Collection;

/*
 | T-320 — phase two, item 3: «مهلة تصعيد».
 |
 | T-240 gave the data desk a working-day window and a morning that chases it.
 | A complaint had nothing of the sort: the follow-up screen shows it sitting
 | there, and whoever does not open that screen does not know it is sitting.
 |
 | So, every morning: a complaint still waiting past its window wakes the desk
 | that holds it and tells the desk above. Once — the stamp is what stops it
 | becoming a daily noise that everybody learns to ignore.
 |
 | Rule 10 — an alert carries the reference and nothing of the complaint: not
 | its subject, not who it is about, not a word the reporter wrote.
 */
class ComplaintEscalation
{
    /**
     * Desks that owe an answer right now.
     *
     * `awaiting_reporter` is deliberately out: that ticket is waiting on the
     * person who opened it, and chasing the desk for an answer it cannot give
     * teaches the desk to ignore the chase.
     */
    public const OWED_BY_THE_DESK = ['new', 'assigned', 'in_progress'];

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly OverdueTaskAlerts $windows,
    ) {}

    public static function dueWorkingDays(): int
    {
        return (int) Setting::value(
            'complaint_due_working_days',
            config('sanabel.setting_defaults.complaint_due_working_days')
        );
    }

    /** @return Collection<int,Complaint> */
    public function overdue(): Collection
    {
        return Complaint::query()
            ->whereIn('status', self::OWED_BY_THE_DESK)
            ->whereNull('alerted_at')
            ->get()
            ->filter(fn (Complaint $complaint) => $complaint->created_at
                && $this->windows->dueAt($complaint->created_at, static::dueWorkingDays())->isPast());
    }

    /** Sends what is due, stamps it, records it. Returns how many. */
    public function run(): int
    {
        $above = $this->escalationRecipients();
        $sent = 0;

        foreach ($this->overdue() as $complaint) {
            // A complaint with no owner is the case this exists for: nobody
            // has picked it up, so there is nobody to wake and the desk above
            // is the whole of the answer.
            $owner = $complaint->owner_id ? [$complaint->owner_id] : [];

            /*
             | Addressed to nobody is worse than not sent at all (T-95). The
             | row is left unstamped, so it is chased the day the association
             | names somebody rather than passed over for good.
             */
            if ($owner === [] && $above === []) {
                continue;
            }

            foreach ($owner as $recipient) {
                $this->notifications->send($recipient, 'complaint_overdue', [
                    // `reference_no` and not `reference`: the notification
                    // service keeps an allow-list of payload keys, which is
                    // rule 10's guard, and a key outside it is dropped.
                    'reference_no' => $complaint->reference_no,
                ]);
            }

            foreach ($above as $recipient) {
                $this->notifications->send($recipient, 'complaint_escalated', [
                    'reference_no' => $complaint->reference_no,
                ]);
            }

            $complaint->forceFill(['alerted_at' => now()])->save();

            AuditLog::create([
                'actor_id' => null,
                'actor_role' => null,
                'action' => 'complaint_overdue',
                'entity_type' => Complaint::class,
                'entity_id' => $complaint->getKey(),
                'after_json' => [
                    'due_working_days' => static::dueWorkingDays(),
                    'alerted' => count($owner),
                    'escalated' => count($above),
                ],
            ]);

            $sent++;
        }

        return $sent;
    }

    /** @return array<int,int> */
    private function escalationRecipients(): array
    {
        $roles = (array) Setting::value(
            'complaint_escalation_roles',
            config('sanabel.setting_defaults.complaint_escalation_roles')
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
