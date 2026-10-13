<?php

return [
    // One base currency. USD exists only as a reference rate for reading approval thresholds.
    'currency' => env('SANABEL_CURRENCY', 'SYP'),

    /*
     | How a date is written out.
     |
     | The interface language is Arabic (APP_LOCALE=ar), but Carbon's plain
     | `ar` writes the months as «أكتوبر» and Syria writes «تشرين الأول».
     | Only the month names differ between the two; the weekdays are identical.
     | T-83 put a month on the donor's card, so this stopped being cosmetic.
     */
    'date_locale' => env('SANABEL_DATE_LOCALE', 'ar_SY'),

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
            /*
             | T-66 — /livewire/update, which T-57 missed because its test only
             | looked at the api/ prefix. It is the write path for the donor
             | portal, the family portal and every panel screen, and the public
             | case list reaches it without signing in at all.
             |
             | Generous on purpose: a live form sends a request per field, and a
             | clerk working through a long family file legitimately makes
             | hundreds in a few minutes. The point is to stop a script, not to
             | slow the office down.
             */
            'livewire' => env('SANABEL_RATE_LIVEWIRE', 300),
        ],

        /*
         | T-84 — APP-07. What a browser, a proxy or a CDN may keep a copy of.
         |
         | Laravel's own default on a page that has a session is
         | «no-cache, private». That stops a shared cache holding it and makes
         | the browser revalidate — but it still lets the browser *store* it.
         | On a shared office machine, or a delegate's phone handed to someone
         | else, the back button after signing out then redraws a family file
         | from disk. `no-store` is the difference, and it is what the
         | association's own file asks for.
         |
         | The rule is not a list of personal pages, because a list is how a
         | page gets missed: every response to a signed-in request is
         | no-store, whatever route it came from. `paths` is only for what can
         | be reached *without* signing in and must still never be kept — the
         | signed media route above all, whose URL is short-lived precisely
         | because the file behind it is a family's paper.
         |
         | Deliberately not here: the public pages. A guest reading the news or
         | the masked case list is reading what everyone reads, and making a
         | CDN forget it between visitors buys nothing.
         */
        'no_store' => [
            // Every signed-in response, on any route.
            'authenticated' => env('SANABEL_NO_STORE_AUTHENTICATED', true),
            'paths' => [
                // The signed media route: a family's documents (FILE-03).
                'storage/*',
                // Livewire's own write and upload endpoints. The asset routes
                // under livewire/ are deliberately left out — livewire.js is a
                // static file, and no-store on it means a fresh download on
                // every page.
                'livewire/update',
                'livewire/upload-file',
                'livewire/preview-file/*',
                // Every API response. Behind auth:sanctum today, so this is
                // the belt to that braces.
                'api/*',
                // A login form carries a CSRF token tied to one session.
                'login',
                'admin/login',
                'association/login',
                'provider/login',
            ],
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
    /*
     | T-81 — the privacy policy in force. The text lives in
     | database/data/policies/privacy-<version>.md, and this names which version
     | a family is agreeing to. They move together: a new version is a new file
     | and a changed number, so «which text did this family agree to» always has
     | an answer, and a typo fixed in the CMS does not become a new version.
     */
    'privacy_policy' => [
        'version' => '1.0',
        'adopted_on' => '2026-10-13',
        // Their legal adviser may revise it; until then this is what is in force.
        'pending_legal_review' => true,
    ],

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
        // Where a donor sends the money when neither the family nor its
        // association says otherwise: direct|association|both.
        'default_transfer_mode' => 'association',
        // How long a closed file keeps its personal data before it may be
        // anonymised. The association settled on five years on 12 Oct 2026,
        // reading it off the Saudi commercial-register period for an
        // establishment, and asked whether it could be moved later: it is a
        // setting, so it moves from the settings screen without a deploy.
        // The period runs from the file's last activity, not from its
        // creation, so a family still being helped is never due.
        'anonymise_after_years' => 5,
        // Whether the system administrator may take part in anonymising a
        // family. Closed by default and opened from the settings screen when
        // the association needs it (13 Oct 2026). Opening it is a change to a
        // setting, so it carries a name and a time.
        'anonymise_admin_allowed' => false,
        /*
         | T-78 — the share of a family's monthly need the donor is asked for.
         | 100 means the whole need, which is what the system did before this
         | existed, so nothing changes until the association lowers it. A region
         | may hold its own share, and overrides this one.
         */
        'funding_target_percent' => 100,
        // Whether the sufficiency note («الكفاية التامة … والحملة تمثّل …»)
        // appears above a family on the public list, and in the basket. Two
        // switches, because the association asked for each place separately.
        'show_sufficiency_note' => true,
        'show_sufficiency_note_in_basket' => true,
        /*
         | T-82 — the last four digits of the household's own account, shown to
         | a donor. Asked for on 13 October so a donor can satisfy themselves
         | that the family they are funding or visiting is the family they
         | meant, and granted on their instruction: «والتحويل يتم لصندوق سنابل
         | وليس للفقير مباشرة», so it identifies and never pays.
         |
         | On by default because that is what they asked for, and a switch
         | because their own privacy policy (clause 4, adopted the same day)
         | forbids disclosing a beneficiary's data «بما يؤدي إلى التعرف عليهم»
         | — so the association can close it again without a deploy.
         */
        'show_wallet_last4' => true,
        /*
         | T-83 — whether a family's monthly funding appears on that family's
         | own campaign, asked for on 13 October: «خيار اظهار او اخفاء التمويل
         | الشهري مكان الحمله الخاصة بالمحتاج». A campaign answers a one-off
         | cost and the monthly need goes on beside it, so the association can
         | have both on the page or the campaign alone.
         */
        'show_monthly_funding_on_campaign' => true,
    ],
];
