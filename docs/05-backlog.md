# 05 — Backlog

**One task per session.** `Read CLAUDE.md and docs/, then do T-07 only. Simplest working version. Show your plan first.`

Hard tasks come early on purpose — T-14, T-19 and T-22 carry the schedule risk. Do not leave them to the end.

## Foundation

| # | Task | Needs | Done when |
|---|---|---|---|
| T-01 | Laravel + Filament + Postgres + queues + RTL Arabic layout + `lang/ar` | — | Blank Arabic RTL admin page loads |
| T-02 | `regions` tree + seed Daraa's five areas as data | T-01 | No region name in code |
| T-03 | Reference tables + `settings` + Filament screens + Excel import + versioning | T-02 | Admin adds a region's rates without a developer |
| T-04 | Roles, permissions, Policies, region global scope | T-01 | Council write-denial test passes |
| T-05 | `Auditable` trait + `audit_log` (writes only, append-only) | T-04 | Every case/money write appears in the log |
| T-06 | Beneficiary tables + members + income + housing + health + factories | T-02 | Matches `02-data-model.md` |

## Assessment

| # | Task | Needs | Done when |
|---|---|---|---|
| T-07 | **Need engine** service — write tests first | T-03, T-06 | `06-tests.md §Engine` green |
| T-08 | **Score service** (M/V/H/U/D/B + bands + overcrowding) — tests first | T-07 | `06-tests.md §Score` green |
| T-09 | `assessments` + `snapshot_json` + override with reason | T-08 | Editing config leaves old assessments untouched |
| T-10 | Beneficiary form in Filament, multi-step, computed values read-only | T-06, T-07 | No field accepts a score |

## Case flow

| # | Task | Needs | Done when |
|---|---|---|---|
| T-11 | Case status flow + approve/reject with reason + separation of duties | T-04, T-10 | Creator cannot be final approver |
| T-12 | Media upload to private bucket + signed URLs | T-06 | No public media URL |
| T-13 | `change_requests` + recompute on material fields | T-11 | Post-approval edits go through review |
| T-14 | **Delegate PWA: offline visit form, IndexedDB queue, sync** | T-10 | Full visit created offline, syncs once |
| T-15 | Sync conflict: never overwrite — store as a new visit and flag for admin | T-14 | Conflict test passes |
| T-16 | Duplicate check on `national_id_hash` + admin merge | T-06 | Merge keeps all history |
| T-17 | Association portal (Filament panel): own + referred, coordination lookup | T-04, T-16 | Lookup returns four values only |

## Money

| # | Task | Needs | Done when |
|---|---|---|---|
| T-18 | `MaskedCaseResource` + a leak test per donor route | T-06 | Nothing identifying leaks |
| T-19 | **Basket + reservation with DB lock + expiry job + allocation split** | T-18 | Concurrency test passes |
| T-20 | `PaymentGateway` + `ManualDriver` + donations + unique `transaction_ref` + funds | T-01 | Duplicate ref rejected |
| T-21 | Verification queue (approve/reject with reason) + coverage recompute + notify | T-20, T-19 | Coverage updates only on verify |
| T-22 | **Ranking service** + zero-remaining exclusion + waiting bonus | T-09, T-21 | Covered case disappears from the list |
| T-23 | Reversal for verified donations (never edit) | T-20 | Correction creates a linked row |
| T-24 | Campaigns: goal, reserved, progress, mandatory surplus text, auto-close | T-20 | Cannot publish without policy text |
| T-25 | Sponsorships + generated installments + reminders + lapse rule | T-20 | Unpaid never counts as coverage |
| T-26 | Donor portal: masked browse, two lists, basket, my donations, badges | T-18, T-19 | Works on mobile in Arabic |
| T-27 | Delivery proof + case closure | T-21 | Case closes only with proof |
| T-28 | Bulk distribution: generate, freeze `list_json`, execute, partial, notify | T-22 | Frozen list never regenerates |

## Modules

| # | Task | Needs | Done when |
|---|---|---|---|
| T-29 | Providers + referral cards (code, expiry, single use, revoke) | T-04 | Provider sees three fields only |
| T-30 | Provider panel: own offers, verify card, confirm delivery | T-29 | Nothing else visible |
| T-31 | Job market: profile (approved cases only), filters, contact via admin | T-11 | No phone or address exposed |
| T-32 | Memberships + subscriptions + membership fund | T-20 | Never counted as family coverage |
| T-33 | Complaints (basic): reference number, category, owner, status, resolution | T-04 | Never assigned to the subject |
| T-34 | Notifications (in-app + email) with Arabic templates, no PII in body | T-01 | Every template links to login |
| T-35 | Reports + admin dashboard: cases, donations, coverage, regions, overdue | T-22 | Weekly view without manual export |
| T-36 | Public website + CMS (pages, banners, news, published campaigns, contact) | T-24 | Admin edits content without a developer |

## Delivery

| # | Task | Done when |
|---|---|---|
| T-37 | Documented API + service accounts (no AI, no webhooks beyond a simple event log) | Contract published |
| T-38 | Security pass: 2FA for admin/council, rate limits on ID search, export permission | Checks pass |
| T-39 | Run `06-tests.md` end to end | Green |
| T-40 | Synthetic seed data for training and demo | No real data |
| T-41 | Deploy, encrypted backup, **restore test**, rollback plan | Restore verified |
| T-42 | Handover: code, DB, ERD, deployment guide, role manuals, training session | Delivered |

## Recommended order note

Do a two-day spike on **T-14 (offline sync)** and **T-19 (basket concurrency)** before committing to a final date. These two are the only places where the estimate could move materially.
