<?php

namespace App\Services;

/**
 * T-138 — the four bands the association set for a progress bar.
 *
 * «تحت 40 بالمئة اللون الاحمر, ومن 41 الى 80 باللون البرتقالي, ومن 81 الى 99
 * اخضر فاتح, اما المكتملة تكون باللون الاخضر الغامق» — asked of the campaigns
 * on 26 October and of the funding bars again on 28 October, which is the
 * answer to why it is here rather than on `Campaign`: a bar is a bar, and the
 * family cards drew one in a single colour while the campaign cards drew four.
 *
 * The CSS names the colours (`.meter-fill-low` and its three neighbours) and
 * lifts each one in the dark scheme. This names the bands.
 */
class ProgressBand
{
    public static function of(?int $percent): string
    {
        return match (true) {
            $percent >= 100 => 'complete',
            $percent >= 81 => 'high',
            $percent >= 41 => 'mid',
            default => 'low',
        };
    }
}
