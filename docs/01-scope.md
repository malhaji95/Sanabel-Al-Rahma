# 01 — Scope

## What phase 1 is

The full journey, working end to end:

**register family → field visit → assess → approve → publish (masked) → donor gives → payment verified → coverage updates → delivery proved → case closed**

Everything below serves that journey. Nothing else is in phase 1.

## Modules

| # | Module | Depth in phase 1 |
|---|---|---|
| 1 | Region tree + living/rent references per region | Versioned values, Excel import, admin screen |
| 2 | Beneficiary file (family, members, income, housing, health, documents) | Full form, no deletion |
| 3 | Delegate field app (PWA) + **offline visit entry and sync** | Offline form + queue + sync |
| 4 | Field verification → area supervisor → admin approval | Simple status flow |
| 5 | Need engine (F/M/V/H/U/D/B) + score + dynamic ranking + override | Computed, snapshotted |
| 6 | Case lifecycle + reassessment due dates | Status column + scheduled job |
| 7 | Change requests after approval (before/after, recompute) | One generic table |
| 8 | Duplicate detection + merge | Hash match + admin merge |
| 9 | Permissions + server-side masking | 9 roles, region scope |
| 10 | Association portal | Own + referred cases only |
| 11 | Donor portal + **basket of several families + 24h hold** | Reservation with DB lock |
| 12 | Campaigns (goal, progress, auto-close) | Simple |
| 13 | Sponsorships + monthly installments | Generated schedule + reminders |
| 14 | Manual payment: proof upload, verification queue, unique ref | Core money path |
| 15 | Delivery/receipt proof + case closure | Upload + confirm |
| 16 | Bulk distribution batches | Generate list, freeze, execute, prove |
| 17 | Health network: providers, discounts, referral card, provider account | Referral + proof, no cost workflow |
| 18 | Job market | Profile + contact request via admin |
| 19 | Memberships + subscriptions | Separate fund, receipts, statuses |
| 20 | Complaints (basic) | Reference number, status, owner |
| 21 | Financial core: fund separation, reversal, simple approval | Ledger-lite |
| 22 | Notifications (in-app + email) + basic reports + dashboard | Templates in Arabic |
| 23 | Public website + CMS | Pages, banners, news, published campaigns |
| 24 | Security, audit log, backup + restore test | Baseline |
| 25 | API + events readiness (no AI built) | Documented API, service accounts |
| 26 | Handover: code, DB, ERD, deployment, training, warranty | Delivery |

## Roles (9 active)

`beneficiary` · `delegate` · `area_supervisor` · `case_officer` · `association` · `donor` · `service_provider` · `admin` · `council` (read-only)

Roles live in a table. Adding a role later is a data insert, not a rewrite.
Do **not** build 22 roles now.

## Not in phase 1

- Sham Cash API — money moves by hand, the system records it
- Native mobile apps
- Any AI feature, chatbot, or automated decision
- Advanced complaints/investigations, conflict-of-interest module, glass-break
- In-kind donations, procurement, assets, risk register
- Advanced financial: disbursement orders, reconciliation, period closing, accountant import
- Child-protection pathway (removed by the client). Baseline privacy still applies: never publish anything identifying a child.
- Multi-currency machinery — one base currency (SYP); a USD reference rate exists only for reading approval thresholds
