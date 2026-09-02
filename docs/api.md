# API Contract

Phase 1. No AI, no outbound webhooks — an event log only (`audit_log`).

Base URL: `{APP_URL}/api`
Auth: Laravel Sanctum bearer token. Every route below requires one.
Language: request and response bodies are English keys; every human-readable
string is Arabic.

## Service accounts

A service account is an ordinary `users` row with a role and a region, issued a
token instead of a password session:

```bash
php artisan tinker
>>> $user = App\Models\User::create([
...     'name' => 'Partner integration',
...     'email' => 'integration@example.org',
...     'password' => Str::random(40),
...     'role_id' => App\Models\Role::where('key', 'association')->value('id'),
...     'region_id' => 2,
... ]);
>>> $user->createToken('partner')->plainTextToken;
```

The token inherits the role's permissions and region scope exactly. There is no
way to grant an integration more than a person in the same role can do.

## Donor routes

Every one of these answers with the masked shape and nothing else.

| Method | Path | Notes |
|---|---|---|
| GET | `/donor/cases/{supportType}` | `monthly` or `one_time`; ranked; `page`, `per_page`, `region_id` |
| GET | `/donor/case/{case}` | published cases only |
| GET | `/donor/basket` | the donor's current basket |
| POST | `/donor/basket/items` | `beneficiary_id`, `amount` |
| POST | `/donor/basket/reserve` | 422 when a family has less remaining than asked |
| GET | `/donor/donations` | own donations; allocations are masked |
| POST | `/donor/donations` | `amount`, `transaction_ref`, optional `receipt_media_id`, `basket_id` |
| GET | `/donor/campaigns` | published campaigns, with the surplus policy text |
| GET | `/donor/campaigns/{campaign}` | |
| GET | `/donor/jobs` | published profiles; no phone, no address |
| POST | `/donor/jobs/{profile}/contact` | contact is handled by admin, never passed through |

### Masked case shape

```json
{
  "file_number": "DEMO-0012",
  "area_ar": "إزرع",
  "family_size": 5,
  "age_bands": { "child": 3, "adult": 2, "elderly": 0 },
  "need_type": "monthly",
  "need_type_label": "دعم شهري",
  "need_amount": 63000,
  "currency": "SYP",
  "coverage_percent": 40,
  "coverage_label": "جزئي",
  "remaining_amount": 37800,
  "urgency_label": "أولوية متوسطة",
  "has_chronic_illness": true,
  "rent_band": "إيجار متوسط",
  "is_renting": true,
  "waiting_weeks": 6
}
```

These keys are the complete list. A name, national ID, phone, address, wallet,
landlord, diagnosis, exact age, exact rent or raw score is never present, and a
test asserts the response carries nothing outside this set.

## Internal routes

| Method | Path | Permission |
|---|---|---|
| GET | `/cases` | `view_full_case`, region-scoped and paginated |
| GET | `/cases/{case}` | `view_full_case` |
| POST | `/cases` | `create_case`; rejects a duplicate national ID |
| POST | `/cases/{case}/approve` | `approve_case`; the creator is refused |
| POST | `/cases/{case}/reject` | `approve_case`; `reason_ar` required |
| POST | `/cases/{case}/publish` | `approve_case`; approved cases only |
| POST | `/cases/{case}/change-requests` | `request_change` |
| POST | `/cases/{case}/deliveries` | `confirm_delivery`; `type`, `proof_media_id` |
| POST | `/donations/{donation}/verify` | `verify_payment` |
| POST | `/donations/{donation}/reject` | `verify_payment`; `reason` required |
| POST | `/coordination/lookup` | `search_by_national_id`; **10 requests/minute** |
| POST | `/visits/sync` | `record_visit`; idempotent by `client_uuid` |
| GET | `/referrals/{code}` | `verify_referral`; own referrals only |
| POST | `/referrals/{code}/redeem` | `verify_referral`; `proof_media_id` |

`council` is refused on every write route above, whatever its stored
permissions say.

### Coordination lookup

Four values. Nothing else ever leaves this endpoint.

```json
{ "registered": true, "has_active_assessment": true,
  "supported_this_period": false, "coverage": "partial" }
```

### Referral card

```json
{ "file_number": "DEMO-0012", "valid": true, "valid_until": "2026-10-02",
  "discount_type": "percentage", "discount_value": 25 }
```

### Visit sync

The device generates `client_uuid`. Pushing the same queue twice creates one
visit, not two — the unique index enforces it.

```json
{
  "visits": [{
    "client_uuid": "6f1c...",
    "beneficiary_id": 12,
    "visited_at": "2026-09-02T10:00:00Z",
    "note_ar": "…",
    "recommendation": "approve",
    "is_reassessment": false,
    "base_version_at": "2026-09-01T08:00:00Z",
    "data": {}
  }]
}
```

`base_version_at` is what the device last saw. If the case changed on the server
after it, the visit is still stored and flagged for admin review — nothing is
overwritten. The response reports `{ "synced": n, "conflicts": n, "visit_ids": {} }`.

## Errors

| Status | Meaning |
|---|---|
| 403 | the role lacks the permission, or the record is out of scope |
| 404 | the record does not exist, or is not published |
| 422 | validation, a duplicate `transaction_ref`, or a reservation that exceeds the remaining need |
| 429 | rate limit (the national ID lookup) |

Money errors carry an Arabic `message` written for the person reading it, e.g.
`رقم الحوالة مستخدم من قبل. يرجى المراجعة قبل المتابعة.`

## What does not exist in phase 1

No payment API — a human transfers the money and the system records it. No
outbound webhooks. No AI or automated decision endpoint. No route accepts
`M`, `H`, `D`, `B` or `base_score`: they are computed, never supplied.
