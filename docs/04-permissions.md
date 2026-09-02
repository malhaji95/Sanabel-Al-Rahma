# 04 — Permissions

Keep it simple: a `roles` table, a `permissions` table, a pivot, and Laravel Policies.
Region scoping via one global scope on the models that have `region_id`.

## Matrix

| Ability | beneficiary | delegate | supervisor | case_officer | association | donor | provider | admin | council |
|---|:--:|:--:|:--:|:--:|:--:|:--:|:--:|:--:|:--:|
| create case | — | ✔ | ✔ | ✔ | ✔ | — | — | ✔ | — |
| edit draft | — | ✔ own | ✔ area | ✔ | ✔ own | — | — | ✔ | — |
| upload media | — | ✔ | ✔ | ✔ | ✔ | — | — | ✔ | — |
| record visit | — | ✔ | ✔ | — | — | — | — | — | — |
| recommend | — | ✔ | ✔ | ✔ | — | — | — | — | — |
| approve / reject / publish | — | — | — | — | — | — | — | ✔ | — |
| suspend / graduate | — | — | — | — | — | — | — | ✔ | — |
| override score | — | — | — | — | — | — | — | ✔ | — |
| edit config & references | — | — | — | — | — | — | — | ✔ | — |
| view full case | own | ✔ area | ✔ area | ✔ | ✔ own+referred | — | — | ✔ | ✔ |
| view masked case | — | — | — | — | — | ✔ | — | ✔ | ✔ |
| search by national ID | — | ✔ area | ✔ area | ✔ | ✔ own scope | — | — | ✔ | ✔ |
| request change | — | ✔ | ✔ | ✔ | ✔ | — | — | — | — |
| approve change | — | — | — | — | — | — | — | ✔ | — |
| merge duplicates | — | — | — | — | — | — | — | ✔ | — |
| donate / basket / sponsor | — | — | — | — | — | ✔ | — | — | — |
| verify payment | — | — | — | — | — | — | — | ✔ | — |
| manage campaigns | — | — | — | — | — | — | — | ✔ | — |
| create/execute distribution | — | — | — | — | — | — | — | ✔ | — |
| confirm delivery | — | ✔ | ✔ | ✔ | ✔ | — | ✔ own | ✔ | — |
| manage own offers | — | — | — | — | — | — | ✔ | ✔ | — |
| verify referral card | — | — | — | — | — | — | ✔ | ✔ | — |
| publish job profile | ✔ own | ✔ | — | ✔ | ✔ | — | — | ✔ | — |
| browse job market | — | — | — | — | — | ✔ | — | ✔ | ✔ |
| manage members | — | — | — | — | — | — | — | ✔ | — |
| file complaint | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | — |
| handle complaint | — | — | — | — | — | — | — | ✔ | — |
| manage CMS | — | — | — | — | — | — | — | ✔ | — |
| manage users & roles | — | — | — | — | — | — | — | ✔ | — |
| view reports | — | own | ✔ area | ✔ | ✔ own | own | own | ✔ | ✔ |

## Four hard rules

1. **`council` cannot write anything.** Deny it in every Policy explicitly. One test per write route.
2. **`donor` only ever receives `MaskedCaseResource`.** One test per donor route.
3. **`association` sees its own and referred cases only.** Out-of-scope lookup by national ID returns four values only: registered? · has active assessment? · supported for this need this period? · coverage none/partial/full. Nothing else.
4. **`provider` sees only the referral presented to it**: file number, validity, discount type.

Everything else can be a plain Policy check.
