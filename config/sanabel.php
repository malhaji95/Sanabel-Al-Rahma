<?php

return [
    // One base currency. USD exists only as a reference rate for reading approval thresholds.
    'currency' => env('SANABEL_CURRENCY', 'SYP'),

    /*
     | Where media is stored. Never a public URL either way.
     |
     |   media_local — private local disk, signed expiring URLs (the default)
     |   media       — S3-compatible private bucket (preferred once available)
     */
    'media_disk' => env('SANABEL_MEDIA_DISK', 'media_local'),

    /*
     | The relation values that count as an orphan for the V factor. A domain
     | value, so it is data rather than a literal buried in the score service.
     */
    'orphan_relation_keys' => ['orphan', 'يتيم'],

    /*
     | Defaults used only when the matching row is missing from the `settings` table.
     | Everything operational lives in `settings` so it changes without a deploy.
     */
    'setting_defaults' => [
        'basket_hold_hours' => 24,
        // How long before a hold runs out the donor hears about it.
        'basket_warn_hours' => 6,
        // Days before the current month ends when the next month opens for
        // funding, so a family is not left with a gap at the turn of the month.
        'next_month_opens_days_before' => 7,
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
        // Coverage thresholds behind the priority badge a donor sees. Below the
        // first is the highest priority; at 100% the case is complete. The
        // association moves these from the settings screen.
        'priority_critical_below' => 41,
        'priority_middle_below' => 61,
        'referral_validity_days' => 30,
        // Where a donor sends the money when neither the family nor its
        // association says otherwise: direct|association|both.
        'default_transfer_mode' => 'association',
    ],
];
