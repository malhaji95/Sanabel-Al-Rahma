# 06 — Tests That Matter

Write these. Do not chase coverage beyond them.

## Engine

- [ ] 2 adults + 3 children, adult=5000 child=2000 ⇒ need 16,000
- [ ] Same family, region with adult=5500 child=2500 ⇒ 18,500
- [ ] Rent reference applies only to renting households
- [ ] Each member counted once, under one class
- [ ] Gap never negative when income exceeds need
- [ ] **Editing a region rate does not change an existing assessment**
- [ ] `snapshot_json` contains the versions used
- [ ] No route accepts M, H, D, B or BaseScore as input

## Score

- [ ] 3 persons per habitable room ⇒ overcrowding 25; 3.5 ⇒ 50; 6 ⇒ 100
- [ ] Kitchen and bathroom excluded from room count
- [ ] Unemployed adult, no documented condition ⇒ `unable_to_earn = false`
- [ ] Full-time student aged 20, no income ⇒ `dependent = true`, `unable_to_earn = false`
- [ ] Override stores automatic score, new score, reason, approver

## Ranking

- [ ] Score 100 with 60% covered ⇒ current score 40
- [ ] Unfunded case at 50 can outrank a 60%-funded case at 100
- [ ] **Fully covered case disappears from the funding list — waiting bonus does not bring it back**
- [ ] Pledges and unverified proofs do not change ranking

## Money

- [ ] **Duplicate `transaction_ref` is rejected**
- [ ] Coverage changes only after verification, never on upload
- [ ] Rejection returns a reason and changes nothing
- [ ] **A verified donation cannot be updated; correction creates a reversal row**
- [ ] **Membership payment can never be allocated to a family**
- [ ] A family with no wallet can still receive help paid to a provider

## Basket

- [ ] **Two donors reserving the last remaining amount concurrently — only one succeeds**
- [ ] Expired reservation is released and the family returns to the list
- [ ] One donation splits correctly across the chosen families
- [ ] Verified amount lower than basket total ⇒ proportional allocation, no failure
- [ ] **A family cannot be reserved beyond its remaining need**

## Sponsorship

- [ ] Cannot create without an end date
- [ ] Installments generated for every month in the range
- [ ] Unpaid installment does not change coverage and does not hide the case
- [ ] Two consecutive unpaid ⇒ lapsed, family returns to the list

## Permissions and privacy

- [ ] **`council` is rejected on every write route** (one test per route)
- [ ] **No donor route returns a name, ID, phone, address or raw score** (one test per route)
- [ ] Association cannot open an out-of-scope case
- [ ] Coordination lookup returns four values only
- [ ] Provider sees only file number, validity, discount type
- [ ] Creator cannot be the final approver
- [ ] Nothing identifying a child appears in donor or public output

## Offline

- [ ] Complete a visit with no network, then sync it
- [ ] **Syncing twice creates one visit, not two** (`client_uuid` unique)
- [ ] Server changed since last sync ⇒ new visit stored, conflict flagged, nothing overwritten

## Closure and distribution

- [ ] A case cannot close without a delivery proof
- [ ] Approved distribution list is frozen and never regenerated
- [ ] Partial execution records which items failed

## Other

- [ ] Campaign without surplus policy text cannot be published
- [ ] Expired referral card is refused; a used card cannot be reused
- [ ] Revoking a case's approval hides its job profile
- [ ] Backup restores successfully into a clean database
