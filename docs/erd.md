# ERD

Generated from the live schema. Table and column names are exactly as in
`02-data-model.md`.

```mermaid
erDiagram
    REGIONS {
        int id
        int parent_id
        string name_ar
        string type
        bool is_active
        int created_by
        timestamp created_at
        timestamp updated_at
        timestamp deleted_at
    }
    REGION_RATES {
        int id
        int region_id
        string person_class
        int amount
        string currency
        date effective_from
        int version
        int created_by
        timestamp created_at
        timestamp updated_at
        timestamp deleted_at
    }
    REGION_RENT_REFERENCE {
        int id
        int region_id
        string family_size_band
        int reference_rent
        string currency
        date effective_from
        int version
        int created_by
        timestamp created_at
        timestamp updated_at
        timestamp deleted_at
    }
    ADJUSTMENTS_CATALOG {
        int id
        string key
        string name_ar
        int amount
        string currency
        int region_id
        date effective_from
        int version
        int created_by
        timestamp created_at
        timestamp updated_at
        timestamp deleted_at
    }
    SCORING_WEIGHTS {
        int id
        string factor_key
        decimal weight
        date effective_from
        int version
        int created_by
        timestamp created_at
        timestamp updated_at
        timestamp deleted_at
    }
    SETTINGS {
        int id
        string key
        json value_json
        int updated_by
        timestamp created_at
        timestamp updated_at
    }
    BENEFICIARIES {
        int id
        string file_number
        text national_id_encrypted
        string national_id_hash
        string first_name
        string father_name
        string family_name
        text phone_encrypted
        int region_id
        string marital_status
        text wallet_encrypted
        string support_type
        string status
        timestamp last_assessment_at
        timestamp next_assessment_due_at
        string source
        int merged_into_id
        bool duplicate_review_flag
        int approved_by
        timestamp approved_at
        text reject_reason_ar
        timestamp published_at
        int created_by
        timestamp created_at
        timestamp updated_at
        timestamp deleted_at
        timestamp urgency_deadline_at
        int documented_debt
    }
    HOUSEHOLD_MEMBERS {
        int id
        int beneficiary_id
        string relation
        string name_ar
        int birth_year
        string gender
        string person_class
        bool dependent
        bool unable_to_earn
        bool is_student
        bool has_documented_condition
        text notes_ar
        int created_by
        timestamp created_at
        timestamp updated_at
        timestamp deleted_at
    }
    INCOMES {
        int id
        int beneficiary_id
        string source_type
        int amount
        string currency
        bool is_stable
        int created_by
        timestamp created_at
        timestamp updated_at
        timestamp deleted_at
    }
    HOUSING {
        int id
        int beneficiary_id
        string housing_type
        int monthly_rent
        string currency
        int habitable_rooms
        int safety_band
        int services_band
        int eviction_band
        string landlord_name_ar
        text landlord_phone_encrypted
        int created_by
        timestamp created_at
        timestamp updated_at
        timestamp deleted_at
    }
    HEALTH_RECORDS {
        int id
        int beneficiary_id
        int member_id
        int severity_band
        int economic_impact_band
        int care_burden_band
        int monthly_medical_cost
        string currency
        text description_ar
        int evidence_media_id
        int created_by
        timestamp created_at
        timestamp updated_at
        timestamp deleted_at
    }
    VISITS {
        int id
        int beneficiary_id
        int delegate_id
        uuid client_uuid
        timestamp visited_at
        text note_ar
        string recommendation
        bool is_reassessment
        json payload_json
        bool conflict_flag
        text conflict_reason
        timestamp base_version_at
        timestamp synced_at
        int created_by
        timestamp created_at
        timestamp updated_at
        timestamp deleted_at
    }
    ASSESSMENTS {
        int id
        int beneficiary_id
        int visit_id
        int monthly_need
        int stable_income
        int gap
        string currency
        decimal base_score
        json factors_json
        json snapshot_json
        date valid_until
        string status
        int created_by
        int approved_by
        timestamp approved_at
        timestamp created_at
        timestamp updated_at
        timestamp deleted_at
    }
    OVERRIDES {
        int id
        int assessment_id
        decimal auto_score
        decimal new_score
        text reason_ar
        int requested_by
        int approved_by
        timestamp expires_at
        int created_by
        timestamp created_at
        timestamp updated_at
        timestamp deleted_at
    }
    CHANGE_REQUESTS {
        int id
        string entity_type
        int entity_id
        json payload_json
        json old_json
        text reason_ar
        bool is_material
        int requested_by
        string status
        int reviewed_by
        timestamp reviewed_at
        text review_note_ar
        int created_by
        timestamp created_at
        timestamp updated_at
        timestamp deleted_at
    }
    MEDIA {
        int id
        string owner_type
        int owner_id
        string kind
        string storage_key
        string visibility
        string mime
        int size_bytes
        int uploaded_by
        int created_by
        timestamp created_at
        timestamp updated_at
        timestamp deleted_at
    }
    FUNDS {
        int id
        string key
        string name_ar
        bool can_fund_families
        timestamp created_at
        timestamp updated_at
    }
    DONORS {
        int id
        int user_id
        string name_ar
        text phone_encrypted
        string email
        text wallet_encrypted
        int donations_count
        string badge
        int created_by
        timestamp created_at
        timestamp updated_at
        timestamp deleted_at
    }
    DONATIONS {
        int id
        int donor_id
        string route
        int amount
        string currency
        string transaction_ref
        int receipt_media_id
        string status
        int verified_by
        timestamp verified_at
        text reject_reason
        int fund_id
        int basket_id
        int reversal_of_id
        int created_by
        timestamp created_at
        timestamp updated_at
        timestamp deleted_at
    }
    DONATION_ALLOCATIONS {
        int id
        int donation_id
        int beneficiary_id
        int campaign_id
        int amount
        string currency
        timestamp created_at
        timestamp updated_at
    }
    BASKETS {
        int id
        int donor_id
        string status
        timestamp reserved_until
        int created_by
        timestamp created_at
        timestamp updated_at
        timestamp deleted_at
    }
    BASKET_ITEMS {
        int id
        int basket_id
        int beneficiary_id
        int amount
        string currency
        timestamp created_at
        timestamp updated_at
    }
    CAMPAIGNS {
        int id
        int beneficiary_id
        string title_ar
        text body_ar
        int goal_amount
        int collected_amount
        int reserved_amount
        string currency
        text wallet_encrypted
        text surplus_policy_text_ar
        bool is_published
        string status
        int fund_id
        int created_by
        timestamp created_at
        timestamp updated_at
        timestamp deleted_at
    }
    SPONSORSHIPS {
        int id
        int donor_id
        int beneficiary_id
        int amount
        string currency
        date start_date
        date end_date
        string status
        int created_by
        timestamp created_at
        timestamp updated_at
        timestamp deleted_at
    }
    SPONSORSHIP_INSTALLMENTS {
        int id
        int sponsorship_id
        string period
        date due_date
        int amount
        string currency
        string status
        int donation_id
        timestamp reminded_at
        timestamp created_at
        timestamp updated_at
    }
    DISTRIBUTIONS {
        int id
        int region_id
        string title_ar
        int total_amount
        int per_family_amount
        string currency
        json criteria_json
        json list_json
        string status
        int created_by
        int approved_by
        timestamp approved_at
        timestamp created_at
        timestamp updated_at
        timestamp deleted_at
    }
    DISTRIBUTION_ITEMS {
        int id
        int distribution_id
        int beneficiary_id
        int amount
        string currency
        string status
        text failure_reason_ar
        int proof_media_id
        timestamp created_at
        timestamp updated_at
    }
    DELIVERIES {
        int id
        int beneficiary_id
        int donation_id
        string type
        int proof_media_id
        text note_ar
        int confirmed_by
        timestamp confirmed_at
        int created_by
        timestamp created_at
        timestamp updated_at
        timestamp deleted_at
    }
    MEMBERS {
        int id
        int user_id
        string membership_no
        string name_ar
        string category
        string status
        date joined_at
        int created_by
        timestamp created_at
        timestamp updated_at
        timestamp deleted_at
    }
    SUBSCRIPTIONS {
        int id
        int member_id
        string period
        int amount
        string currency
        date due_date
        string status
        int payment_media_id
        int fund_id
        timestamp created_at
        timestamp updated_at
    }
    PROVIDERS {
        int id
        int user_id
        string name_ar
        string type
        string specialty_ar
        int region_id
        string discount_type
        int discount_value
        date valid_until
        string status
        int created_by
        timestamp created_at
        timestamp updated_at
        timestamp deleted_at
    }
    REFERRALS {
        int id
        int beneficiary_id
        int provider_id
        string code
        timestamp issued_at
        timestamp expires_at
        string status
        timestamp used_at
        int proof_media_id
        int created_by
        timestamp created_at
        timestamp updated_at
        timestamp deleted_at
    }
    JOB_PROFILES {
        int id
        int beneficiary_id
        string trade_key
        text summary_ar
        int region_id
        string availability
        string status
        int created_by
        timestamp created_at
        timestamp updated_at
        timestamp deleted_at
    }
    JOB_REQUESTS {
        int id
        string requester_name_ar
        text contact_encrypted
        string trade_key
        int region_id
        int job_profile_id
        text description_ar
        string status
        int handled_by
        int created_by
        timestamp created_at
        timestamp updated_at
        timestamp deleted_at
    }
    COMPLAINTS {
        int id
        string reference_no
        int submitted_by
        string subject_ar
        text body_ar
        string category
        int against_user_id
        string status
        int owner_id
        text resolution_ar
        int created_by
        timestamp created_at
        timestamp updated_at
        timestamp deleted_at
    }
    AUDIT_LOG {
        int id
        int actor_id
        string actor_role
        string action
        string entity_type
        int entity_id
        json before_json
        json after_json
        timestamp created_at
    }
    APP_NOTIFICATIONS {
        int id
        string channel
        int recipient_id
        string template_key
        json payload_json
        string status
        timestamp sent_at
        timestamp read_at
        timestamp created_at
        timestamp updated_at
    }
    PAGES {
        int id
        string slug
        string title_ar
        text body_ar
        string image
        bool is_published
        int sort_order
        int created_by
        timestamp created_at
        timestamp updated_at
        timestamp deleted_at
    }
    POSTS {
        int id
        string slug
        string title_ar
        text body_ar
        string image
        bool is_published
        int sort_order
        timestamp published_at
        int created_by
        timestamp created_at
        timestamp updated_at
        timestamp deleted_at
    }
    BANNERS {
        int id
        string title_ar
        text body_ar
        string image
        string link
        bool is_published
        int sort_order
        int created_by
        timestamp created_at
        timestamp updated_at
        timestamp deleted_at
    }
    USERS {
        int id
        string name
        string email
        timestamp email_verified_at
        string password
        string remember_token
        timestamp created_at
        timestamp updated_at
        int role_id
        int region_id
        int association_id
        string phone_encrypted
        bool is_active
        timestamp deleted_at
        text two_factor_secret
        timestamp two_factor_confirmed_at
    }
    ROLES {
        int id
        string key
        string name_ar
        bool is_read_only
        timestamp created_at
        timestamp updated_at
    }
    PERMISSIONS {
        int id
        string key
        string name_ar
        timestamp created_at
        timestamp updated_at
    }
    PERMISSION_ROLE {
        int id
        int role_id
        int permission_id
        string scope
        timestamp created_at
        timestamp updated_at
    }
    USERS ||--o{ ADJUSTMENTS_CATALOG : "created_by"
    REGIONS ||--o{ ADJUSTMENTS_CATALOG : "region_id"
    USERS ||--o{ APP_NOTIFICATIONS : "recipient_id"
    USERS ||--o{ ASSESSMENTS : "approved_by"
    BENEFICIARIES ||--o{ ASSESSMENTS : "beneficiary_id"
    USERS ||--o{ ASSESSMENTS : "created_by"
    VISITS ||--o{ ASSESSMENTS : "visit_id"
    USERS ||--o{ AUDIT_LOG : "actor_id"
    USERS ||--o{ BANNERS : "created_by"
    BASKETS ||--o{ BASKET_ITEMS : "basket_id"
    BENEFICIARIES ||--o{ BASKET_ITEMS : "beneficiary_id"
    USERS ||--o{ BASKETS : "created_by"
    DONORS ||--o{ BASKETS : "donor_id"
    USERS ||--o{ BENEFICIARIES : "approved_by"
    USERS ||--o{ BENEFICIARIES : "created_by"
    REGIONS ||--o{ BENEFICIARIES : "region_id"
    BENEFICIARIES ||--o{ CAMPAIGNS : "beneficiary_id"
    USERS ||--o{ CAMPAIGNS : "created_by"
    FUNDS ||--o{ CAMPAIGNS : "fund_id"
    USERS ||--o{ CHANGE_REQUESTS : "created_by"
    USERS ||--o{ CHANGE_REQUESTS : "requested_by"
    USERS ||--o{ CHANGE_REQUESTS : "reviewed_by"
    USERS ||--o{ COMPLAINTS : "against_user_id"
    USERS ||--o{ COMPLAINTS : "created_by"
    USERS ||--o{ COMPLAINTS : "owner_id"
    USERS ||--o{ COMPLAINTS : "submitted_by"
    BENEFICIARIES ||--o{ DELIVERIES : "beneficiary_id"
    USERS ||--o{ DELIVERIES : "confirmed_by"
    USERS ||--o{ DELIVERIES : "created_by"
    DONATIONS ||--o{ DELIVERIES : "donation_id"
    BENEFICIARIES ||--o{ DISTRIBUTION_ITEMS : "beneficiary_id"
    DISTRIBUTIONS ||--o{ DISTRIBUTION_ITEMS : "distribution_id"
    USERS ||--o{ DISTRIBUTIONS : "approved_by"
    USERS ||--o{ DISTRIBUTIONS : "created_by"
    REGIONS ||--o{ DISTRIBUTIONS : "region_id"
    BENEFICIARIES ||--o{ DONATION_ALLOCATIONS : "beneficiary_id"
    CAMPAIGNS ||--o{ DONATION_ALLOCATIONS : "campaign_id"
    DONATIONS ||--o{ DONATION_ALLOCATIONS : "donation_id"
    BASKETS ||--o{ DONATIONS : "basket_id"
    USERS ||--o{ DONATIONS : "created_by"
    DONORS ||--o{ DONATIONS : "donor_id"
    FUNDS ||--o{ DONATIONS : "fund_id"
    USERS ||--o{ DONATIONS : "verified_by"
    USERS ||--o{ DONORS : "created_by"
    USERS ||--o{ DONORS : "user_id"
    BENEFICIARIES ||--o{ HEALTH_RECORDS : "beneficiary_id"
    USERS ||--o{ HEALTH_RECORDS : "created_by"
    HOUSEHOLD_MEMBERS ||--o{ HEALTH_RECORDS : "member_id"
    BENEFICIARIES ||--o{ HOUSEHOLD_MEMBERS : "beneficiary_id"
    USERS ||--o{ HOUSEHOLD_MEMBERS : "created_by"
    BENEFICIARIES ||--o{ HOUSING : "beneficiary_id"
    USERS ||--o{ HOUSING : "created_by"
    BENEFICIARIES ||--o{ INCOMES : "beneficiary_id"
    USERS ||--o{ INCOMES : "created_by"
    BENEFICIARIES ||--o{ JOB_PROFILES : "beneficiary_id"
    USERS ||--o{ JOB_PROFILES : "created_by"
    REGIONS ||--o{ JOB_PROFILES : "region_id"
    USERS ||--o{ JOB_REQUESTS : "created_by"
    USERS ||--o{ JOB_REQUESTS : "handled_by"
    JOB_PROFILES ||--o{ JOB_REQUESTS : "job_profile_id"
    REGIONS ||--o{ JOB_REQUESTS : "region_id"
    USERS ||--o{ MEDIA : "created_by"
    USERS ||--o{ MEDIA : "uploaded_by"
    USERS ||--o{ MEMBERS : "created_by"
    USERS ||--o{ MEMBERS : "user_id"
    USERS ||--o{ OVERRIDES : "approved_by"
    ASSESSMENTS ||--o{ OVERRIDES : "assessment_id"
    USERS ||--o{ OVERRIDES : "created_by"
    USERS ||--o{ OVERRIDES : "requested_by"
    USERS ||--o{ PAGES : "created_by"
    PERMISSIONS ||--o{ PERMISSION_ROLE : "permission_id"
    ROLES ||--o{ PERMISSION_ROLE : "role_id"
    USERS ||--o{ POSTS : "created_by"
    USERS ||--o{ PROVIDERS : "created_by"
    REGIONS ||--o{ PROVIDERS : "region_id"
    USERS ||--o{ PROVIDERS : "user_id"
    BENEFICIARIES ||--o{ REFERRALS : "beneficiary_id"
    USERS ||--o{ REFERRALS : "created_by"
    PROVIDERS ||--o{ REFERRALS : "provider_id"
    USERS ||--o{ REGION_RATES : "created_by"
    REGIONS ||--o{ REGION_RATES : "region_id"
    USERS ||--o{ REGION_RENT_REFERENCE : "created_by"
    REGIONS ||--o{ REGION_RENT_REFERENCE : "region_id"
    USERS ||--o{ REGIONS : "created_by"
    USERS ||--o{ SCORING_WEIGHTS : "created_by"
    USERS ||--o{ SETTINGS : "updated_by"
    DONATIONS ||--o{ SPONSORSHIP_INSTALLMENTS : "donation_id"
    SPONSORSHIPS ||--o{ SPONSORSHIP_INSTALLMENTS : "sponsorship_id"
    BENEFICIARIES ||--o{ SPONSORSHIPS : "beneficiary_id"
    USERS ||--o{ SPONSORSHIPS : "created_by"
    DONORS ||--o{ SPONSORSHIPS : "donor_id"
    FUNDS ||--o{ SUBSCRIPTIONS : "fund_id"
    MEMBERS ||--o{ SUBSCRIPTIONS : "member_id"
    REGIONS ||--o{ USERS : "region_id"
    ROLES ||--o{ USERS : "role_id"
    BENEFICIARIES ||--o{ VISITS : "beneficiary_id"
    USERS ||--o{ VISITS : "created_by"
    USERS ||--o{ VISITS : "delegate_id"
```

## Groups

- **Reference** — `regions`, `region_rates`, `region_rent_reference`, `adjustments_catalog`, `scoring_weights`, `settings`
- **Beneficiary** — `beneficiaries`, `household_members`, `incomes`, `housing`, `health_records`
- **Assessment** — `visits`, `assessments`, `overrides`, `change_requests`, `media`
- **Money** — `funds`, `donors`, `donations`, `donation_allocations`, `baskets`, `basket_items`, `campaigns`, `sponsorships`, `sponsorship_installments`, `distributions`, `distribution_items`, `deliveries`
- **Memberships** — `members`, `subscriptions`
- **Health and jobs** — `providers`, `referrals`, `job_profiles`, `job_requests`
- **Support** — `complaints`, `audit_log`, `app_notifications`
- **CMS** — `pages`, `posts`, `banners`
- **Access** — `users`, `roles`, `permissions`, `permission_role`

## Notes

- Money is an integer in the smallest unit, always beside a `currency` column.
  Never a float.
- Every main table carries `created_at`, `updated_at`, `created_by` and
  `deleted_at`. Nothing is hard-deleted.
- `donations.transaction_ref`, `beneficiaries.national_id_hash`,
  `visits.client_uuid`, `referrals.code`, `complaints.reference_no` and
  `members.membership_no` are unique at the database level. Those constraints,
  not application code, are what hold the rules.
- `audit_log` is append-only: the application exposes no update or delete route,
  and the model refuses both.
- `assessments.snapshot_json` freezes the reference values and versions used, so
  editing configuration never rewrites an assessment already stored.
- Self-referencing keys (`regions.parent_id`, `beneficiaries.merged_into_id`,
  `donations.reversal_of_id`) are omitted from the diagram for readability.
