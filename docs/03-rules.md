# 03 — Business Rules

Write tests for the starred (★) rules before implementing them. The rest just need to work.

## 1. Need engine ★

```
MonthlyNeed  = Σ region_rates[member.person_class]
             + Σ applicable adjustments
             + rent_reference (renting households only)
             + approved manual adjustments

StableIncome = Σ incomes where is_stable
Gap          = max(0, MonthlyNeed − StableIncome − Received)
F            = 100 × max(0, MonthlyNeed − StableIncome) ÷ MonthlyNeed
```

- Each member counted under exactly one `person_class`.
- A specific medical bill is a separate request, not part of MonthlyNeed.
- **★ Store `snapshot_json`** on every assessment: the rates, rents, weights and versions used. Editing config later never changes an old assessment.

## 2. Score

```
BaseScore = 0.25F + 0.20M + 0.15V + 0.10H + 0.15U + 0.10D + 0.05B
```

**M (health)** = `0.45×severity + 0.25×economic_impact + 0.15×care_burden + 0.15×cost_burden`
where `cost_burden = min(100, 100 × monthly_medical_cost ÷ MonthlyNeed)`

**V (vulnerability)** = `min(100, 60×dependents_ratio + 15×single_caregiver + 15×orphans + 10×unsupported_elderly)`

**H (housing)** = `0.35×safety + 0.20×overcrowding + 0.15×services + 0.15×eviction + 0.15×rent_burden`
where `rent_burden = min(100, 100 × max(0, min(rent, reference) − 0.30×income) ÷ max(reference,1))`

**U (urgency)**: no deadline 0 · within 90d 25 · within 30d 50 · within 7d 75 · within 48h 100

**D (deprivation)** = `100 × (1 − min(1, confirmed_support_90d ÷ essential_need_90d))`

**B (debt)** = `100 × min(1, documented_necessary_debt ÷ (3 × MonthlyNeed))`

**No API accepts M, H, D, B or BaseScore directly.** They are computed.

### Bands (0/25/50/75/100)

| Band | severity | economic_impact | care_burden | safety | eviction |
|---|---|---|---|---|---|
| 0 | no impact | none | none | safe | none |
| 25 | stable chronic | limited/temporary | occasional | minor issues | small arrears |
| 50 | periodic treatment | clear reduction | regular daily | deterioration | arrears or warning |
| 75 | severe/disabling | major disruption | intensive, affects carer's work | significant hazard | formal notice |
| 100 | direct danger | total loss of earning | near-continuous | uninhabitable | imminent/lost |

**Overcrowding** by persons per habitable room (kitchen/bath/corridor excluded): ≤2 → 0 · ≤3 → 25 · ≤4 → 50 · ≤5 → 75 · >5 → 100

**Services**: complete 0 · partial lack 50 · fundamental lack 100

### Dependency

- `dependent = true`: under 18, full-time student under 24 with no income, or 65+ with no work income
- `unable_to_earn = true`: **only** with a documented condition preventing regular work. Unemployment alone is not inability.

## 3. Ranking ★

```
Remaining     = max(0, (need − confirmed) ÷ need)
CurrentScore  = BaseScore × Remaining
WaitingBonus  = min(10, floor(waiting_days ÷ 7))
Priority      = min(100, CurrentScore + WaitingBonus)
```

- **★ `Remaining = 0` removes the case from the funding list.** The bonus must not resurrect a covered case.
- Only `verified` donations count. Pledges and unverified proofs do not.
- Recompute after every verification.
- Monthly cases and one-time cases are two separate lists in the donor UI.

## 4. Override

Admin may change a score. Store: automatic score, new score, reason, who requested, who approved. The automatic score is never erased.

## 5. Payments ★

Manual only. A human transfers the money; the system records and verifies.

Flow: donor picks target → transfers outside the platform → enters amount + `transaction_ref` + uploads receipt → admin queue → approve/reject with reason → coverage and ranking update → both notified.

- **★ `transaction_ref` has a unique index.** Duplicate ⇒ rejected with a "please review" message.
- **★ A `verified` donation is never edited.** A correction creates a new row with `reversal_of_id`.
- Every donation carries a `fund_id`. **★ Membership money can never be allocated to a family.**
- A beneficiary wallet is optional — help paid to a hospital, creditor or school needs no wallet.

## 6. Basket ★

Donor picks any number of published families, sets an amount per family, and reserves.

- Hold duration from `settings` (default 24h).
- **★ Reserve inside `DB::transaction` with `lockForUpdate` on the target rows.** Never check-then-write outside a transaction.
- Expired baskets are released by a scheduled job every 5 minutes.
- After verification, the donation is split across `donation_allocations` for the chosen families.
- **★ A family cannot be reserved beyond its remaining need** — this is what prevents double coverage.
- If the verified amount is less than the basket total, allocate proportionally and note it. Do not fail.

## 7. Campaigns

Goal, collected, reserved, progress bar. Pledges stop when `collected + reserved ≥ goal`. Auto-close at goal → `awaiting_execution`, not `completed`. `surplus_policy_text_ar` is mandatory before publishing and is shown to the donor before payment.

## 8. Sponsorships

Start date and end date are required. The system generates one installment per month in the range. Reminder to donor 3 days before due, and a flag on the admin dashboard when overdue past the grace period (default 7 days). Two consecutive unpaid ⇒ `lapsed` and the family returns to the funding list. **An unpaid installment never counts as coverage.**

## 9. Delivery and closure

Funding is not completion. A case closes only when a `deliveries` row exists with proof: a receipt, a confirmation, or a provider invoice depending on the aid type.

## 10. Reassessment

`next_assessment_due_at` from `settings`: stable 180 days · severe or sponsored 90 · emergency or job loss 30. Past due ⇒ `needs_reassessment` flag and demoted in ranking. **Existing support is not stopped.** New sponsorships are not accepted until reassessed. Admin decision after the visit: continue / adjust / graduate / suspend, with a reason.

## 11. Duplicates

`national_id_hash` unique. Exact match blocks creating a second file. Similar phone or wallet raises a review flag for admin — never auto-merge. Merge is admin-only, picks a primary file, and moves donations and history across without deleting anything.

## 12. Distribution

Admin sets region, criteria and amount → system generates the list → admin reviews and approves → **list frozen in `list_json`** → transfers executed manually and confirmed one by one → `completed` or `partial` → all families notified.

## 13. Memberships

Separate from donations entirely. Member number, category, monthly or yearly subscription, payment proof, receipt, statuses (active/overdue/suspended/expired). **Subscription money goes to the `membership` fund and is never family coverage.**

## 14. Complaints (basic)

Form → reference number → category → owner → status → resolution. **A complaint is never assigned to the person it is about.** No investigation workflow in phase 1.

## 15. Masking ★

`MaskedCaseResource` is the only thing a donor ever receives.

**Shown:** file number, area (not village), family size, need type, need amount, coverage %, urgency label.
**Never shown:** names of anyone, national ID, phone, address, wallet, landlord, media, diagnosis, exact age, exact rent, raw score.

Age → band. Illness → "chronic illness". Rent → band.
Nothing identifying a child is ever published anywhere.
