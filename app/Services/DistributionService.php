<?php

namespace App\Services;

use App\Models\Distribution;
use App\Models\DistributionItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * docs/03-rules.md §12. Generate → review → approve (list frozen) → execute
 * one by one → completed or partial → notify.
 */
class DistributionService
{
    public function __construct(
        private readonly RankingService $ranking,
        private readonly NotificationService $notifications,
    ) {}

    /** Only ever runs while the distribution is still a draft. */
    public function generateList(Distribution $distribution): Distribution
    {
        if ($distribution->status !== 'draft') {
            throw new \RuntimeException(__('sanabel.distributions.list_frozen'));
        }

        $criteria = $distribution->criteria_json ?? [];
        $supportType = $criteria['support_type'] ?? 'monthly';
        $limit = (int) ($criteria['limit'] ?? 100);

        $candidates = $this->ranking
            ->fundingList($supportType, $distribution->region_id)
            ->take($limit);

        $list = $candidates->map(fn (array $row) => [
            'beneficiary_id' => $row['beneficiary']->id,
            'file_number' => $row['beneficiary']->file_number,
            'priority' => $row['ranking']['priority'],
            'amount' => $distribution->per_family_amount,
        ])->values()->all();

        $distribution->forceFill([
            'list_json' => $list,
            'total_amount' => count($list) * $distribution->per_family_amount,
        ])->save();

        return $distribution->refresh();
    }

    /** Approval freezes the list. It is never regenerated afterwards. */
    public function approve(Distribution $distribution, User $approver): Distribution
    {
        if (empty($distribution->list_json)) {
            throw new \RuntimeException('Generate the list before approving it.');
        }

        return DB::transaction(function () use ($distribution, $approver) {
            $distribution->forceFill([
                'status' => 'approved',
                'approved_by' => $approver->getKey(),
                'approved_at' => now(),
            ])->save();

            foreach ($distribution->list_json as $row) {
                DistributionItem::firstOrCreate(
                    [
                        'distribution_id' => $distribution->getKey(),
                        'beneficiary_id' => $row['beneficiary_id'],
                    ],
                    ['amount' => $row['amount'], 'currency' => $distribution->currency, 'status' => 'pending'],
                );
            }

            return $distribution->refresh();
        });
    }

    public function execute(DistributionItem $item, int $proofMediaId): DistributionItem
    {
        $item->forceFill(['status' => 'executed', 'proof_media_id' => $proofMediaId])->save();

        $this->notifications->send($item->beneficiary->created_by, 'distribution_executed', [
            'file_number' => $item->beneficiary->file_number,
        ]);

        $this->settle($item->distribution->refresh());

        return $item->refresh();
    }

    public function fail(DistributionItem $item, string $reasonAr): DistributionItem
    {
        $item->forceFill(['status' => 'failed', 'failure_reason_ar' => $reasonAr])->save();

        $this->settle($item->distribution->refresh());

        return $item->refresh();
    }

    /** completed when every item executed, partial when some failed. */
    public function settle(Distribution $distribution): Distribution
    {
        $items = $distribution->items()->get();

        if ($items->where('status', 'pending')->isNotEmpty()) {
            $distribution->forceFill(['status' => 'executing'])->save();

            return $distribution->refresh();
        }

        $distribution->forceFill([
            'status' => $items->where('status', 'failed')->isEmpty() ? 'completed' : 'partial',
        ])->save();

        return $distribution->refresh();
    }
}
