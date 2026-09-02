<?php

return [
    // One base currency. USD exists only as a reference rate for reading approval thresholds.
    'currency' => env('SANABEL_CURRENCY', 'SYP'),

    // Private bucket. Media is never served from a public URL.
    'media_disk' => env('SANABEL_MEDIA_DISK', 's3'),

    /*
     | Defaults used only when the matching row is missing from the `settings` table.
     | Everything operational lives in `settings` so it changes without a deploy.
     */
    'setting_defaults' => [
        'basket_hold_hours' => 24,
        'sponsorship_grace_days' => 7,
        'sponsorship_lapse_after_unpaid' => 2,
        'reassessment_days_stable' => 180,
        'reassessment_days_severe' => 90,
        'reassessment_days_emergency' => 30,
        'badge_silver_min' => 3,
        'badge_gold_min' => 10,
        'verification_target_hours' => 48,
        'deprivation_window_days' => 90,
        'assessment_valid_days' => 180,
        'referral_validity_days' => 30,
    ],
];
