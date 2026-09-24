<?php

namespace App\Exceptions;

use Illuminate\Support\Carbon;
use RuntimeException;

/** A family cannot be reserved beyond its remaining need (docs/03-rules.md §6). */
class ReservationUnavailable extends RuntimeException
{
    public static function exceedsRemaining(string $fileNumber): self
    {
        return new self(__('sanabel.basket.exceeds_remaining').' ('.$fileNumber.')');
    }

    public static function exceedsCampaignGoal(string $title): self
    {
        return new self(__('sanabel.basket.exceeds_goal').' ('.$title.')');
    }

    public static function monthNotOpen(Carbon $month): self
    {
        return new self(__('sanabel.basket.month_not_open', ['month' => $month->translatedFormat('F Y')]));
    }

    public static function campaignClosed(string $title): self
    {
        return new self(__('sanabel.basket.campaign_closed').' ('.$title.')');
    }
}
