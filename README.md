# سنابل الرحمة — Sanabel Al-Rahma

Phase 1 of the Sanabel Al-Rahma platform, built to the spec in [`docs/`](docs/).
By **Takteek Agency**.

The whole journey works end to end:

> register family → field visit → assess → approve → publish (masked) →
> donor gives → payment verified → coverage updates → delivery proved → case closed

## Stack

- Laravel 11 (PHP 8.3+) · PostgreSQL · Redis queues
- Filament v3 for the internal panels — admin, association, provider
- Donor portal and public site: Blade + Livewire + Tailwind, Arabic and RTL
- Delegate field app: a PWA route on the same app, IndexedDB for offline
- Media: S3-compatible private bucket, signed URLs only
- Tests: Pest

## Getting started

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
# point DB_* at a PostgreSQL database, then:
php artisan migrate --seed
npm run build          # or: npm run dev
php artisan serve
```

Demo and training data (synthetic families only — rule 11):

```bash
php artisan migrate:fresh
php artisan db:seed --class=SyntheticDataSeeder
```

Demo accounts, all with the password from `SEED_DEMO_PASSWORD` (default
`password`): `admin@`, `officer@`, `supervisor@`, `delegate@`, `association@`,
`council@`, `provider@`, `donor@` — each `@sanabel.local`.

`admin` and `council` are asked to enrol a second factor on first login.

## Where things are

| Path | What |
|---|---|
| `app/Services/NeedEngine.php` | MonthlyNeed, StableIncome, Gap, F |
| `app/Services/ScoreService.php` | M/V/H/U/D/B, bands, overcrowding, rent burden |
| `app/Services/RankingService.php` | Remaining, current score, waiting bonus |
| `app/Services/BasketService.php` | reservation under `lockForUpdate` |
| `app/Services/DonationService.php` | record, verify, reject, reversal, allocation split |
| `app/Http/Resources/MaskedCaseResource.php` | the only thing a donor ever receives |
| `app/Filament/` | admin, association and provider panels |
| `app/Livewire/` | donor portal |
| `resources/js/field.js` | the offline queue in the delegate PWA |
| `lang/ar/` | every user-facing string |
| `config/brand.php` | colours, logo variants, typeface — the one swap point |
| `public/brand/` | logo variants and app icons |
| `docs/api.md` | the API contract |
| `docs/deployment.md` | deploying, backups, the restore test |
| `docs/erd.md` | the schema, generated from the live database |
| `docs/brand.md` | the visual identity: colours, typeface, logo use |

## The seven rules that are never simplified

Each is implemented and each has a test.

| # | Rule | Where |
|---|---|---|
| 1 | `transaction_ref` unique in the database | unique index on `donations`; `DuplicateTransactionRef` turns it into an Arabic message |
| 2 | Donors never see names, IDs, phones, addresses | `MaskedCaseResource`, with one leak test per donor route |
| 3 | Nothing is hard-deleted | `SoftDeletes` everywhere; every policy returns false for `forceDelete` |
| 4 | Every write to a case or money is logged | `Auditable` trait → append-only `audit_log`, with PII redacted |
| 5 | A verified payment is never edited | `Donation::booted()` refuses it; corrections create a linked reversal |
| 6 | Reservations happen inside a DB transaction | `BasketService::reserve()` with `lockForUpdate` |
| 7 | Every assessment stores a snapshot | `snapshot_json` holds the rates, rents, weights and versions used |

## Commands

```bash
php artisan test                          # the suite
php artisan schedule:work                 # basket expiry, reminders, reassessment flags
php artisan sanabel:release-expired-baskets
php artisan sanabel:sponsorship-cycle
php artisan sanabel:flag-reassessments
php artisan sanabel:send-notifications
BACKUP_PASSPHRASE=... scripts/verify-restore.sh   # the restore test
php scripts/basket-race-check.php 10              # two-process reservation race
```

## Tests

`docs/06-tests.md` lists the tests that matter; all of them are written and
green. Coverage beyond that list is deliberately not chased.

```
tests/Feature/EngineTest.php                 need engine + snapshot immutability
tests/Feature/ScoreTest.php                  score factors, bands, overrides
tests/Feature/RankingTest.php                remaining, waiting bonus, exclusion
tests/Feature/MoneyTest.php                  duplicate ref, verify-only coverage, reversal, funds
tests/Feature/BasketTest.php                 reservation, row lock, expiry, split
tests/Feature/SponsorshipTest.php            installments, lapse, reminders
tests/Feature/PrivacyTest.php                one leak test per donor route
tests/Feature/PermissionsTest.php            council write-denial per route, scoping
tests/Feature/OfflineSyncTest.php            offline entry, double sync, conflict
tests/Feature/ClosureAndDistributionTest.php closure proof, frozen list, partial
tests/Feature/OtherRulesTest.php             campaigns, referrals, complaints, merge, audit
tests/Feature/SecurityTest.php               TOTP vectors, 2FA gate, rate limit, media
tests/Feature/PanelSmokeTest.php             every panel screen mounts
tests/Feature/PortalSmokeTest.php            public site, donor flow, field app
```

## Language

Spec, code, identifiers and comments are English. Everything a user sees is
Arabic and right-to-left, from `lang/ar` — a hardcoded user-facing string is a bug.

## Before go-live

`docs/07-decisions.md` lists what is still with the client. The one that blocks
correctness is item 1: **the real living and rent reference values per region**.
Until they are loaded, `ReferenceValueSeeder` writes placeholders and every
computed need is wrong. Load them through
**Reference data → living reference values → import**.
