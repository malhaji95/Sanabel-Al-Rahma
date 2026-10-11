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
     | T-58 — FILE-01. What may be uploaded at all, and how an upload is held.
     |
     | The stored extension is taken from the key of this map, never from the
     | name the browser sent: a file called paper.php that sniffs as a JPEG is
     | stored as a JPEG, and one that sniffs as anything not listed here is
     | refused outright. HEIC is on the list because a delegate photographs a
     | document on an iPhone.
     */
    'media' => [
        'max_bytes' => (int) env('SANABEL_MEDIA_MAX_BYTES', 8 * 1024 * 1024),

        'allowed' => [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/heic' => 'heic',
            'image/heif' => 'heif',
            'application/pdf' => 'pdf',
        ],

        /*
         | FILE-02 asks for an antivirus scan, and no scanner is wired to this
         | system: that needs a service the association has to provide. Turning
         | this on makes every new upload land as `pending` and withholds it
         | until something marks it clean, so the gate is ready the day they
         | have one. Off, an upload that passes the checks above is served.
         */
        'require_external_scan' => env('SANABEL_MEDIA_REQUIRE_SCAN', false),
    ],

    /*
     | The relation values that count as an orphan for the V factor. A domain
     | value, so it is data rather than a literal buried in the score service.
     */
    'orphan_relation_keys' => ['orphan', 'يتيم'],

    /*
     | T-56 — the response headers a browser needs in order to defend the page
     | (APP-06 of the security roadmap). Data, not code, so a directive is
     | tightened without a deploy.
     */
    'security' => [
        'headers' => [
            // Never guess a type: an uploaded file that sniffs as script is
            // the classic way a private bucket becomes an XSS vector.
            'X-Content-Type-Options' => 'nosniff',
            // frame-ancestors below says the same thing to a modern browser;
            // this is for the ones that do not read it.
            'X-Frame-Options' => 'DENY',
            // A file number must not travel to another site in a Referer.
            'Referrer-Policy' => env('SANABEL_REFERRER_POLICY', 'no-referrer'),
            // The field app needs the delegate's position for the survey's GPS
            // question. Nothing here uses a camera, a microphone or payment.
            'Permissions-Policy' => env(
                'SANABEL_PERMISSIONS_POLICY',
                'geolocation=(self), camera=(), microphone=(), payment=(), usb=()',
            ),
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'X-Permitted-Cross-Domain-Policies' => 'none',
        ],

        'csp' => [
            'enabled' => env('SANABEL_CSP_ENABLED', true),
            // Turn this on to watch what a tightened policy would break before
            // it breaks it.
            'report_only' => env('SANABEL_CSP_REPORT_ONLY', false),
            'directives' => [
                'default-src' => ["'self'"],
                // Livewire injects inline script and Alpine compiles with
                // `new Function`. Both have to stay until the front end is
                // built with nonces; no other origin may load script either
                // way, which is where most of the value is.
                'script-src' => ["'self'", "'unsafe-inline'", "'unsafe-eval'"],
                'style-src' => ["'self'", "'unsafe-inline'"],
                // The typeface is self-hosted; nothing is fetched from a CDN.
                'font-src' => ["'self'", 'data:'],
                'img-src' => ["'self'", 'data:', 'blob:'],
                'connect-src' => ["'self'"],
                'media-src' => ["'self'"],
                // The delegate field app registers a service worker.
                'worker-src' => ["'self'", 'blob:'],
                'manifest-src' => ["'self'"],
                'object-src' => ["'none'"],
                'base-uri' => ["'self'"],
                // A login form cannot be pointed at somebody else's server.
                'form-action' => ["'self'"],
                'frame-ancestors' => ["'none'"],
                'upgrade-insecure-requests' => [],
            ],
        ],

        /*
         | T-57 — APP-04 and IAM-03. Requests a minute, per signed-in account
         | and per address for anyone not signed in. Numbers, so the
         | association raises one without a deploy if a delegate on a bad line
         | keeps hitting it.
         */
        'rate_limits' => [
            // The public login form had no limit at all; the panels' own login
            // carries Filament's five a minute.
            'login' => env('SANABEL_RATE_LOGIN', 5),
            'login_per_hour' => env('SANABEL_RATE_LOGIN_HOUR', 20),
            // Reads: a donor paging a list, a delegate opening files.
            'api' => env('SANABEL_RATE_API', 90),
            // Writes: a basket hold, a declared transfer, an approval.
            'api_write' => env('SANABEL_RATE_API_WRITE', 30),
            // A field app coming back online pushes its queued visits in a
            // burst, so this one is deliberately looser than the other writes.
            'sync' => env('SANABEL_RATE_SYNC', 60),
            // The one route that takes a national ID (T-38).
            'national_id' => env('SANABEL_RATE_NATIONAL_ID', 10),
        ],

        'hsts' => [
            'enabled' => env('SANABEL_HSTS_ENABLED', true),
            'max_age' => env('SANABEL_HSTS_MAX_AGE', 31536000),
            'include_subdomains' => env('SANABEL_HSTS_SUBDOMAINS', true),
            // Submitted once and painful to undo, so it is their call on their
            // own domain.
            'preload' => env('SANABEL_HSTS_PRELOAD', false),
        ],
    ],

    /*
     | Defaults used only when the matching row is missing from the `settings` table.
     | Everything operational lives in `settings` so it changes without a deploy.
     */
    'setting_defaults' => [
        'basket_hold_hours' => 24,
        // How long before a hold runs out the donor hears about it.
        'basket_warn_hours' => 6,
        // How long a household has to say a payment arrived before it goes
        // back to the finance desk. Proposed; the association may change it
        // from the settings screen without a deploy.
        'receipt_confirm_days' => 7,
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
        // T-53 — above this total value, a family's surplus (investment or
        // non-essential, sellable) assets bear on eligibility or priority. The
        // association approves the criteria separately, so there is no default:
        // null means no criteria are in force and the total is reported only.
        // Assets held as necessary for the home, for work or for a living are
        // never part of the total, whatever they are worth.
        'surplus_asset_threshold' => null,
        // Where a donor sends the money when neither the family nor its
        // association says otherwise: direct|association|both.
        'default_transfer_mode' => 'association',
    ],
];
