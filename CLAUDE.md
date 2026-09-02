# CLAUDE.md — Sanabel Al-Rahma

## 1. Stack

- Laravel 11 (PHP 8.3) + PostgreSQL + Redis queues
- **Filament v3** for all internal panels (admin, association, delegate desk, provider) — do not hand-roll admin CRUD
- Donor portal + public site: Laravel Blade + Livewire + Tailwind (RTL)
- Delegate field app: same Laravel app as a PWA route + IndexedDB (Dexie) for offline
- Storage: S3-compatible, private bucket, signed URLs
- Tests: Pest

Change this section if you pick differently. Nothing in `docs/` depends on the stack.

## 2. Commands

```bash
composer install && npm install
php artisan migrate --seed
php artisan test
npm run dev
```

## 3. Simplicity mandate — read before every task

- Build **only** what the task says. No extra features, no "while I was here".
- **No abstraction until there are three concrete cases.** No interfaces with one implementation, except `PaymentGateway` (§4.7).
- No new packages without asking. Laravel + Filament covers almost everything here.
- Prefer a `status` string column over a state-machine package.
- Prefer a database constraint over application logic.
- Prefer one clear query over a clever one.
- Handle **only** the edge cases written in `docs/`. If you think of another one, add it to `docs/07-decisions.md` and move on — do not implement it.
- If a requirement is unclear, **stop and ask**. Do not invent a rule.
- Write tests only for what `docs/06-tests.md` lists. Do not chase coverage.

## 4. Rules that are never relaxed

1. **`transaction_ref` unique** — database-level unique index on `donations`. A duplicate is rejected with a message asking for review.
2. **Donor output is masked** — donors are served exclusively by `MaskedCaseResource`. It never contains name, national ID, phone, address, wallet, landlord, media, or the raw score. Every donor-facing route uses it.
3. **No hard delete** — `SoftDeletes` on beneficiaries, cases, donations. No `forceDelete` anywhere.
4. **Audit writes** — an `Auditable` trait on beneficiary, case, donation, assessment, payment, permission change. Logs actor, action, entity, before/after, timestamp. Writes only; reads are not logged in phase 1.
5. **Verified money is immutable** — a `verified` donation is never updated. Corrections create a linked reversal row.
6. **Reservations are transactional** — basket holds and campaign pledges use `DB::transaction` with `lockForUpdate`. Never check-then-write outside a transaction.
7. **Payment abstraction** — one `PaymentGateway` interface, one `ManualDriver`. No automatic transfers exist in phase 1; a human moves the money and the system records it.
8. **Assessment snapshot** — when an assessment is computed, store the reference values and weights used in a JSON column. Config edits never change past assessments.
9. **Money is integers** in the smallest unit, with a `currency` column. Never `float`.
10. **Never log personal data** — no IDs, names, phones, wallets in application logs.
11. **No real data in dev/test.** Seeders generate synthetic families.
12. **Never edit `docs/`** unless asked.

## 5. Conventions

- Table and column names exactly as in `docs/02-data-model.md`.
- All user-facing strings in `lang/ar/*.php`. A hardcoded string is a bug.
- Every page `dir="rtl"`, font Tajawal or IBM Plex Sans Arabic.
- Region names, reference amounts, weights: **data, never code**.
- Every list is paginated and filtered by the user's region scope.
- One commit per task, message starts with the task ID.
