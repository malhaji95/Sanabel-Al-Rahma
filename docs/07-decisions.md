# 07 — Decisions and Open Items

## Defaults already chosen — build these, don't ask

| Setting | Value |
|---|---|
| Base currency | SYP. USD used only as a reference rate for reading approval thresholds |
| Basket hold | 24 hours |
| Sponsorship grace | 7 days; lapse after 2 consecutive unpaid |
| Reassessment | stable 180d · severe/sponsored 90d · emergency 30d |
| Reassessment overdue | flag + demote; support continues; no new sponsorship |
| Badges | by donation count — silver ≥ 3, gold ≥ 10 |
| Notifications | in-app + email only |
| Verification target | 48h, shown as a dashboard number only |
| Duplicate review trigger | same phone + region, or same wallet |
| Media | internal by default; never shown to donors |
| Self-registration | not built in phase 1 |
| Audit reads | not logged in phase 1 (writes only) |

All of these live in the `settings` table so they change without a deploy.

## Waiting on the client — blocks production, not development

| # | Item | Impact |
|---|---|---|
| 1 | Actual living reference and rent values per region | Seeders use placeholders. **Must be loaded before go-live.** |
| 2 | Legal entity receiving funds | Blocks real payments |
| 3 | Approved payment channels and platform wallet | Blocks payment testing |
| 4 | Financial approval thresholds | Defaults used until confirmed |
| 5 | Membership categories and amounts | Blocks opening memberships |
| 6 | Surplus / refund policy text | Blocks publishing campaigns |
| 7 | Zakat policy, if enabled | Blocks zakat fund |
| 8 | Hosting, domain, account ownership | Blocks deployment |
| 9 | Delivery proof standard per aid type | Defaults used until confirmed |
| 10 | Which roles are actually staffed at launch | Affects UAT only |

## Questions Claude Code raised during the build

<!-- date | task | question | what I assumed | status -->

| Date | Task | Question | What I assumed | Status |
|---|---|---|---|---|
| 2026-09-02 | T-08 | `U` (urgency) needs a deadline, but no deadline column is named in `02-data-model.md`. | Added `beneficiaries.urgency_deadline_at` (nullable). Empty ⇒ band 0, which is the "no deadline" case in the table. | Assumed |
| 2026-09-02 | T-08 | `B` (debt) needs `documented_necessary_debt`, also not named in the data model. | Added `beneficiaries.documented_debt` (integer, default 0). | Assumed |
| 2026-09-02 | T-08 | A household can hold several `health_records`. The `M` formula reads as if there is one. | Take the worst band of each kind across the records, and sum `monthly_medical_cost`. A second illness should not dilute the first. | Assumed |
| 2026-09-02 | T-08 | `V` needs `single_caregiver`, `orphans` and `unsupported_elderly`, which are not columns. | Derived: one earning adult with at least one dependent; any member whose relation is "orphan"; an elderly member with no earning adult. Each contributes 0 or 1. | Assumed |
| 2026-09-02 | T-07 | "Σ applicable adjustments" does not say which adjustments apply when. | Four keys, matched from the household: `children_present`, `elderly_present`, `unable_to_earn_present`, `shelter_household`. They are catalogue rows, so the amounts stay data. | Assumed |
| 2026-09-02 | T-03 | "Excel import" — reading `.xlsx` directly needs a new package, and CLAUDE.md §3 says not to add one without asking. | Import reads **CSV**, which is what Excel writes with "Save as CSV". Swapping in a real `.xlsx` reader later is one class. | **Needs a decision** |
| 2026-09-02 | T-22 | Ranking says `Remaining = (need − confirmed) ÷ need`, without defining `need`. | `need = MonthlyNeed − StableIncome` (the funding target). `gap` was not used, because it already subtracts money received and would double-count. | Assumed |
| 2026-09-02 | T-38 | 2FA for admin and council, without adding a package. | TOTP (RFC 6238) implemented in `app/Services/Totp.php`, verified against the RFC test vectors. Works with any authenticator app. | Assumed |
| 2026-09-02 | T-19 | The basket concurrency test cannot run two real transactions inside one `RefreshDatabase` test. | Covered in two places. In the suite: sequential reservations where only one wins, plus an assertion that `SELECT … FOR UPDATE` is really issued against `beneficiaries` inside the transaction. Outside it: `scripts/basket-race-check.php` runs two operating-system processes on two connections, released at the same instant. Verified — 12 rounds, exactly one winner each. | Resolved |
| 2026-09-02 | T-24 | Campaign pledges are described but no pledge table exists; `reserved_amount` is a column. | Kept `reserved_amount` as the whole mechanism, as the "simplest thing that works" mandate implies. Pledges stop at `collected + reserved >= goal`. | Assumed |

