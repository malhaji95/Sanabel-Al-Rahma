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
