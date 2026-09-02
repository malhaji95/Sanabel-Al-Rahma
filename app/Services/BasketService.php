<?php

namespace App\Services;

use App\Exceptions\ReservationUnavailable;
use App\Models\Basket;
use App\Models\BasketItem;
use App\Models\Beneficiary;
use App\Models\Donor;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * docs/03-rules.md §6.
 *
 * Rule 6 — a reservation happens inside DB::transaction with lockForUpdate on
 * the target rows. Never check-then-write outside a transaction: two donors
 * reserving the last remaining amount at the same time must not both succeed.
 */
class BasketService
{
    public function __construct(private readonly CoverageService $coverage) {}

    public function openFor(Donor $donor): Basket
    {
        return Basket::firstOrCreate(
            ['donor_id' => $donor->getKey(), 'status' => 'open'],
            ['reserved_until' => null],
        );
    }

    public function addItem(Basket $basket, Beneficiary $beneficiary, int $amount): BasketItem
    {
        return BasketItem::updateOrCreate(
            ['basket_id' => $basket->getKey(), 'beneficiary_id' => $beneficiary->getKey()],
            ['amount' => $amount, 'currency' => config('sanabel.currency')],
        );
    }

    /**
     * Holds every family in the basket for the configured window.
     *
     * The whole check-and-write runs in one transaction with the beneficiary
     * rows locked, so a concurrent reservation for the same family blocks until
     * this one commits and then sees the updated remaining need.
     */
    public function reserve(Basket $basket): Basket
    {
        $hours = (int) Setting::value(
            'basket_hold_hours',
            config('sanabel.setting_defaults.basket_hold_hours')
        );

        return DB::transaction(function () use ($basket, $hours) {
            $items = $basket->items()->orderBy('beneficiary_id')->get();

            if ($items->isEmpty()) {
                throw new ReservationUnavailable(__('sanabel.basket.empty'));
            }

            // Lock the target rows in a stable order — ascending id avoids deadlocks
            // between two donors holding overlapping baskets.
            $beneficiaries = Beneficiary::withoutGlobalScopes()
                ->whereIn('id', $items->pluck('beneficiary_id'))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($items as $item) {
                $beneficiary = $beneficiaries[$item->beneficiary_id];

                // Computed while the row is locked, so it accounts for every
                // reservation that committed before this transaction started.
                $remaining = $this->coverage->remainingNeed($beneficiary);

                if ($item->amount > $remaining) {
                    throw ReservationUnavailable::exceedsRemaining($beneficiary->file_number);
                }
            }

            $basket->forceFill([
                'status' => 'reserved',
                'reserved_until' => now()->addHours($hours),
            ])->save();

            return $basket->refresh();
        });
    }

    /** Releases one basket back to the funding list. */
    public function release(Basket $basket, string $status = 'expired'): void
    {
        DB::transaction(function () use ($basket, $status) {
            $basket->forceFill(['status' => $status, 'reserved_until' => null])->save();
        });
    }

    /** Run by the scheduler every five minutes. */
    public function releaseExpired(): int
    {
        $expired = Basket::query()
            ->where('status', 'reserved')
            ->whereNotNull('reserved_until')
            ->where('reserved_until', '<=', now())
            ->get();

        foreach ($expired as $basket) {
            $this->release($basket);
        }

        return $expired->count();
    }
}
