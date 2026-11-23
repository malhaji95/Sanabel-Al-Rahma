<?php

namespace App\Services;

use App\Models\Beneficiary;
use App\Models\User;
use Illuminate\Support\Carbon;

/*
 | T-313 — phase two, item 2: «السجلّ الزمني الموحَّد للأسرة».
 |
 | Everything that ever happened to a family was recorded, and recorded well —
 | but in six different tables, each on its own screen. To answer «what has
 | this family been through» somebody opened the file, then the studies, then
 | the visits, then the disbursements, then the sponsorships, and held the
 | dates in their head.
 |
 | This gathers them into one list, newest first. It reads; it writes nothing
 | and stores nothing. The audit log was the obvious place to read from and is
 | the wrong one: it records `created` and `updated` on a row, which is what
 | you need to prove who touched what, and tells a reader nothing about what
 | became of a family. The meaning lives in the domain tables, so that is
 | where this reads.
 */
class FamilyTimeline
{
    /** No family has this many events; the cap is against a runaway page. */
    public const LIMIT = 300;

    /**
     * @return array<int,array{at: Carbon, kind: string, title: string, detail: ?string, actor: ?string}>
     */
    public function for(Beneficiary $beneficiary): array
    {
        $entries = array_merge(
            $this->fileMilestones($beneficiary),
            $this->assessments($beneficiary),
            $this->visits($beneficiary),
            $this->money($beneficiary),
            $this->sponsorships($beneficiary),
            $this->deliveries($beneficiary),
        );

        usort($entries, fn ($a, $b) => $b['at']->getTimestamp() <=> $a['at']->getTimestamp());

        return array_slice($entries, 0, self::LIMIT);
    }

    /**
     * The file's own steps, each from the stamp the step itself writes, so a
     * record imported with its dates reads as truly as one filled in here.
     *
     * @return array<int,array<string,mixed>>
     */
    private function fileMilestones(Beneficiary $beneficiary): array
    {
        $steps = [
            ['created_at', 'created_by', 'opened'],
            ['field_verified_at', 'field_verified_by', 'field_verified'],
            ['endorsed_at', 'endorsed_by', 'endorsed'],
            ['approved_at', 'approved_by', 'approved'],
            ['published_at', null, 'published'],
            ['anonymised_at', 'anonymised_by', 'anonymised'],
        ];

        $entries = [];

        foreach ($steps as [$at, $by, $key]) {
            if (! $beneficiary->{$at}) {
                continue;
            }

            $entries[] = $this->entry(
                $beneficiary->{$at},
                'file',
                __('sanabel.timeline.file.'.$key),
                null,
                $by ? $beneficiary->{$by} : null,
            );
        }

        return $entries;
    }

    /** @return array<int,array<string,mixed>> */
    private function assessments(Beneficiary $beneficiary): array
    {
        $entries = [];

        foreach ($beneficiary->assessments()->latest('id')->get() as $assessment) {
            $figures = __('sanabel.timeline.assessment.figures', [
                'need' => number_format((int) $assessment->monthly_need),
                'currency' => Money::short($assessment->currency),
                'score' => $assessment->base_score,
            ]);

            $entries[] = $this->entry(
                $assessment->created_at,
                'assessment',
                __('sanabel.timeline.assessment.raised'),
                $figures,
                $assessment->created_by,
            );

            if ($assessment->approved_at) {
                $entries[] = $this->entry(
                    $assessment->approved_at,
                    'assessment',
                    __('sanabel.timeline.assessment.approved'),
                    $figures,
                    $assessment->approved_by,
                );
            }
        }

        return $entries;
    }

    /** @return array<int,array<string,mixed>> */
    private function visits(Beneficiary $beneficiary): array
    {
        return $beneficiary->visits()
            ->whereNotNull('visited_at')
            ->latest('visited_at')
            ->get()
            ->map(fn ($visit) => $this->entry(
                $visit->visited_at,
                'visit',
                __($visit->is_reassessment ? 'sanabel.timeline.visit.reassessment' : 'sanabel.timeline.visit.first'),
                null,
                $visit->delegate_id,
            ))
            ->all();
    }

    /**
     * Money that left for this family, and money that was meant to and did
     * not. A failure belongs on the family's record as much as a payment: it
     * is why a month shows nothing.
     *
     * @return array<int,array<string,mixed>>
     */
    private function money(Beneficiary $beneficiary): array
    {
        $entries = [];

        $rows = $beneficiary->newQuery()->getConnection()
            ->table('disbursements')
            ->where('beneficiary_id', $beneficiary->id)
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->get(['amount', 'currency', 'period', 'status', 'executed_at', 'executed_by', 'beneficiary_responded_at', 'dispute_reason_ar']);

        foreach ($rows as $row) {
            if ($row->executed_at) {
                $entries[] = $this->entry(
                    Carbon::parse($row->executed_at),
                    $row->status === 'failed' ? 'money_failed' : 'money',
                    __($row->status === 'failed' ? 'sanabel.timeline.money.failed' : 'sanabel.timeline.money.executed'),
                    __('sanabel.timeline.money.figures', [
                        'amount' => number_format((int) $row->amount),
                        'currency' => Money::short($row->currency),
                        // `period` is the first day of the month it covers,
                        // and a reader wants the month, not the day.
                        'period' => Carbon::parse($row->period)->format('Y-m'),
                    ]),
                    $row->executed_by,
                );
            }

            // The family's own word on it, which is the only step on this list
            // they take themselves.
            if ($row->beneficiary_responded_at) {
                $entries[] = $this->entry(
                    Carbon::parse($row->beneficiary_responded_at),
                    'money',
                    __($row->dispute_reason_ar
                        ? 'sanabel.timeline.money.disputed'
                        : 'sanabel.timeline.money.confirmed'),
                    null,
                    null,
                );
            }
        }

        return $entries;
    }

    /** @return array<int,array<string,mixed>> */
    private function sponsorships(Beneficiary $beneficiary): array
    {
        $entries = [];

        foreach ($beneficiary->sponsorships()->latest('id')->get() as $sponsorship) {
            $figures = __('sanabel.timeline.sponsorship.figures', [
                'amount' => number_format((int) $sponsorship->amount),
                'currency' => Money::short($sponsorship->currency),
            ]);

            if ($sponsorship->start_date) {
                $entries[] = $this->entry(
                    Carbon::parse($sponsorship->start_date),
                    'sponsorship',
                    __('sanabel.timeline.sponsorship.started'),
                    $figures,
                    null,
                );
            }

            /*
             | Only once it has actually ended. Every sponsorship carries an
             | end date — the model refuses one without it — so writing the
             | line from the column alone put «انتهت كفالة» on the record of a
             | sponsorship still running, dated in the future.
             */
            if ($sponsorship->end_date && Carbon::parse($sponsorship->end_date)->isPast()) {
                $entries[] = $this->entry(
                    Carbon::parse($sponsorship->end_date),
                    'sponsorship',
                    __('sanabel.timeline.sponsorship.ended'),
                    null,
                    null,
                );
            }
        }

        return $entries;
    }

    /** @return array<int,array<string,mixed>> */
    private function deliveries(Beneficiary $beneficiary): array
    {
        return $beneficiary->deliveries()
            ->whereNotNull('confirmed_at')
            ->latest('confirmed_at')
            ->get()
            ->map(fn ($delivery) => $this->entry(
                $delivery->confirmed_at,
                'delivery',
                __('sanabel.timeline.delivery.confirmed'),
                $this->deliveryKind($delivery->type),
                $delivery->confirmed_by,
            ))
            ->all();
    }

    /**
     * The kind of help, in Arabic, or nothing at all. `deliveries.type` is
     * whatever the caller passed, so an unnamed kind is left out rather than
     * printed raw: a screen in Arabic does not say «service».
     */
    private function deliveryKind(?string $type): ?string
    {
        if (! $type) {
            return null;
        }

        $key = 'sanabel.timeline.delivery.kinds.'.$type;

        return __($key) === $key ? null : __($key);
    }

    /** @return array{at: Carbon, kind: string, title: string, detail: ?string, actor: ?string} */
    private function entry(Carbon $at, string $kind, string $title, ?string $detail, ?int $actorId): array
    {
        return [
            'at' => $at,
            'kind' => $kind,
            'title' => $title,
            'detail' => $detail,
            'actor' => $actorId ? $this->name($actorId) : null,
        ];
    }

    /** @var array<int,?string> */
    private array $names = [];

    private function name(int $id): ?string
    {
        return $this->names[$id] ??= User::whereKey($id)->value('name');
    }
}
