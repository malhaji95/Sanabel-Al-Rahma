# 02 — Data Model

Names used verbatim. Money = integer in smallest unit + `currency`.
Every main table gets `created_at`, `updated_at`, `created_by`, `deleted_at`.

## Reference data

**regions** — `id, parent_id, name_ar, type(governorate|area|city|village), is_active`

**region_rates** — `id, region_id, person_class(adult|child|elderly), amount, effective_from, version, created_by`

**region_rent_reference** — `id, region_id, family_size_band, reference_rent, effective_from, version`

**adjustments_catalog** — `id, key, name_ar, amount, region_id(nullable), effective_from, version`

**scoring_weights** — `id, factor_key, weight, effective_from, version`

**settings** — `id, key, value_json, updated_by` (grace days, hold hours, thresholds, reassessment days, feature flags — all here, one table)

## Beneficiary

**beneficiaries** — `id, file_number(unique), national_id_encrypted, national_id_hash(unique), first_name, father_name, family_name, phone_encrypted, region_id, marital_status, wallet_encrypted(nullable), support_type(monthly|one_time), status, last_assessment_at, next_assessment_due_at, source(delegate|association), approved_by, approved_at`

`status`: `draft, pending_visit, verified, pending_approval, approved, published, needs_reassessment, suspended, graduated, rejected, merged`

**household_members** — `id, beneficiary_id, relation, name_ar, birth_year, gender, person_class, dependent(bool), unable_to_earn(bool), notes_ar`

**incomes** — `id, beneficiary_id, source_type, amount, currency, is_stable(bool)`

**housing** — `id, beneficiary_id, housing_type, monthly_rent, habitable_rooms, safety_band, services_band, eviction_band, landlord_name_ar, landlord_phone_encrypted`

**health_records** — `id, beneficiary_id, member_id(nullable), severity_band, economic_impact_band, care_burden_band, monthly_medical_cost, description_ar, evidence_media_id`

## Assessment

**assessments** — `id, beneficiary_id, visit_id, monthly_need, stable_income, gap, base_score, factors_json, snapshot_json, valid_until, status(draft|approved|superseded), created_by, approved_by`

**overrides** — `id, assessment_id, auto_score, new_score, reason_ar, requested_by, approved_by, expires_at`

**visits** — `id, beneficiary_id, delegate_id, client_uuid(unique), visited_at, note_ar, recommendation, is_reassessment, synced_at`
> `client_uuid` is generated on the device. The unique index is what prevents duplicate sync.

**change_requests** — `id, entity_type, entity_id, payload_json, old_json, reason_ar, requested_by, status, reviewed_by, reviewed_at`

**media** — `id, owner_type, owner_id, kind, storage_key, visibility(internal|public), uploaded_by`

## Money

**donors** — `id, user_id, name_ar, phone_encrypted, email, wallet_encrypted, donations_count, badge(none|silver|gold)`

**donations** — `id, donor_id, route(direct|platform), amount, currency, transaction_ref(unique), receipt_media_id, status(pending|verified|rejected|reversed), verified_by, verified_at, reject_reason, fund_id, reversal_of_id(nullable)`

**donation_allocations** — `id, donation_id, beneficiary_id(nullable), campaign_id(nullable), amount`
> One donation can cover several families (the basket). This table splits it.

**baskets** — `id, donor_id, status(open|reserved|paid|expired), reserved_until`

**basket_items** — `id, basket_id, beneficiary_id, amount`

**funds** — `id, key(operational|restricted|zakat|membership), name_ar`
> Every donation and membership payment carries a `fund_id`. Membership money can never be counted as family coverage.

**campaigns** — `id, beneficiary_id(nullable), title_ar, goal_amount, collected_amount, reserved_amount, wallet_encrypted, surplus_policy_text_ar, status(active|funded|awaiting_execution|completed|cancelled)`

**sponsorships** — `id, donor_id, beneficiary_id, amount, start_date, end_date, status(active|completed|lapsed|cancelled)`

**sponsorship_installments** — `id, sponsorship_id, period, due_date, amount, status(due|paid|overdue), donation_id(nullable), reminded_at`

**distributions** — `id, region_id, total_amount, per_family_amount, list_json, status(draft|approved|executing|completed|partial), created_by, approved_by`

**distribution_items** — `id, distribution_id, beneficiary_id, amount, status(pending|executed|failed), proof_media_id`

**deliveries** — `id, beneficiary_id, donation_id(nullable), type, proof_media_id, confirmed_by, confirmed_at`
> This is what closes a case: money arrived AND help was delivered.

## Memberships

**members** — `id, user_id, membership_no(unique), category, status(active|overdue|suspended|expired), joined_at`

**subscriptions** — `id, member_id, period, amount, due_date, status(due|paid|overdue), payment_media_id, fund_id`

## Health & jobs

**providers** — `id, name_ar, type(hospital|doctor|pharmacy|lab), specialty_ar, region_id, discount_type, discount_value, valid_until, status`

**referrals** — `id, beneficiary_id, provider_id, code(unique), issued_at, expires_at, status(issued|used|expired|revoked), proof_media_id`

**job_profiles** — `id, beneficiary_id, trade_key, summary_ar, region_id, availability, status(pending|published|hidden)`

**job_requests** — `id, requester_name_ar, contact_encrypted, trade_key, region_id, description_ar, status, handled_by`

## Support

**complaints** — `id, reference_no(unique), submitted_by(nullable), subject_ar, category, against_user_id(nullable), status(new|assigned|resolved|closed), owner_id, resolution_ar`

**audit_log** — `id, actor_id, actor_role, action, entity_type, entity_id, before_json, after_json, created_at`
> Append-only. No update/delete route in the app.

**notifications** — `id, channel(in_app|email), recipient_id, template_key, payload_json, status, sent_at`

## CMS

**pages**, **posts**, **banners** — standard Filament-managed content: `id, title_ar, body_ar, image, is_published, sort_order`
