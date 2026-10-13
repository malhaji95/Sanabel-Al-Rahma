<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Beneficiary;
use App\Models\Campaign;
use App\Models\Caregiver;
use App\Models\ChangeRequest;
use App\Models\Complaint;
use App\Models\Debt;
use App\Models\Delivery;
use App\Models\Disbursement;
use App\Models\DistributionItem;
use App\Models\HealthRecord;
use App\Models\HouseholdMember;
use App\Models\Housing;
use App\Models\Income;
use App\Models\JobProfile;
use App\Models\Media;
use App\Models\Post;
use App\Models\Referral;
use App\Models\Setting;
use App\Models\SupportSource;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * T-65 — destroy the person, keep the record.
 *
 * The association's resolution of 12 October to the conflict between the
 * roadmap's legal-deletion requirement and rule 3: the record is never
 * deleted, and the personal data identifying a human being is destroyed when
 * the law requires it and the statutory retention period has run out.
 *
 * What survives, by their own example: the file number, the region, the
 * status and its dates, the household's shape (how many, which class, who was
 * unable to earn), every band and every figure the assessments rest on, every
 * donation and disbursement, and the audit trail. The association can still
 * answer what it spent, on how many families, where, and who approved it.
 *
 * What goes: every name, every identity number, every telephone number, the
 * wallet, the address and coordinates, the free text that tends to carry a
 * name in it, the family's papers including the file objects themselves, and
 * their own portal account.
 *
 * Irreversible by design. There is no undo and nothing is kept aside to
 * restore from, because a copy kept for restoring would mean the data was
 * never destroyed.
 */
class AnonymiseService
{
    /**
     * Who may do this at all, named by the association on 12 October and
     * widened on 13 October to include both deputies: the chairman of the
     * board of trustees and his deputy, the executive director and his deputy.
     *
     * A role check and not only a permission, in the manner of the council's
     * read-only rule: the system administrator holds every permission key, and
     * the association did not name the system administrator. Destroying a
     * family's identity is not an administrative convenience.
     *
     * Four accounts rather than two, which also answers the risk in the first
     * version: it takes two different people, and with two it waited whenever
     * one of them was away.
     */
    public const AUTHORISED_ROLES = [
        'board_director', 'deputy_board_director',
        'executive_director', 'deputy_executive_director',
    ];

    /**
     * The system administrator is outside that list, by a switch rather than
     * by a rule in the code: «نحتاج تكون صلاحية مدير النظام اختياري متى ما
     * نحتاج نفتحها او نسكرها» (13 Oct 2026).
     *
     * Closed by default, opened from the settings screen when the association
     * needs it, and closed again afterwards. Opening it is itself a change to
     * a setting, so it carries a name and a time in the audit trail — which is
     * the point of a door that can be opened rather than a wall with a hole.
     */
    public const ADMIN_SWITCH = 'anonymise_admin_allowed';

    /**
     * What is cleared, per record. Everything not named here survives, which
     * is the direction that fails safe for the association's own reporting;
     * the test holds the list against the columns that actually exist, so a
     * new personal column cannot be added and quietly missed.
     *
     * @var array<class-string<Model>,array<int,string>>
     */
    public const CLEARED = [
        Beneficiary::class => [
            'phone_encrypted', 'wallet_encrypted',
            'town_ar', 'quarter_ar', 'latitude', 'longitude', 'birth_date', 'workplace_ar',
            'surveyed_by_ar',
            'previous_aid_ar', 'livelihood_proposal_ar', 'proposal_skills_ar', 'furnishing_ar',
            // The link back to the old system's archive, which still holds the
            // name. Leaving it would make this a redirection, not an erasure.
            'legacy_file_no', 'legacy_review_json',
        ],
        HouseholdMember::class => [
            'national_id_encrypted', 'spouse_national_id_encrypted',
            'relation_other_ar', 'birth_date', 'spouse_name_ar',
            'workplace_ar', 'school_grade_ar', 'certificate_ar', 'skills_ar', 'notes_ar',
        ],
        Housing::class => ['landlord_name_ar', 'landlord_phone_encrypted', 'type_other_ar'],
        HealthRecord::class => [
            'description_ar', 'treatment_place_ar', 'medicines_ar',
            'doctor_name_ar', 'doctor_phone_encrypted',
        ],
        Debt::class => ['creditor_address_ar', 'creditor_phone_encrypted', 'reason_ar', 'nature_ar'],
        // name_ar here is another association, not a person, so it stays.
        SupportSource::class => ['notes_ar'],
        Caregiver::class => [
            'national_id_encrypted', 'relation_other_ar', 'income_source_ar', 'notes_ar',
            'neighbours_relation',
        ],
        Asset::class => ['kind_other_ar', 'necessity_reason_ar', 'details_ar'],
        Visit::class => ['note_ar', 'payload_json', 'conflict_reason'],

        /*
         | Everything else that hangs off the file and carries words somebody
         | wrote about this family. Missing these was the gap: a sweep that
         | clears the file and leaves a published story naming them, their
         | signed consent form and their medical report is a false assurance.
         */
        Delivery::class => ['note_ar'],
        Disbursement::class => ['dispute_reason_ar', 'failure_reason_ar', 'reject_reason_ar'],
        DistributionItem::class => ['failure_reason_ar'],
        Referral::class => [],
        Complaint::class => ['body_ar', 'resolution_ar'],
        // Public content about this family. Cleared and taken down below.
        Post::class => ['body_ar', 'excerpt_ar', 'consent_signed_by_ar', 'image'],
        Campaign::class => ['body_ar', 'surplus_policy_text_ar', 'wallet_encrypted'],
        // The before and after of every field being erased sit in here.
        ChangeRequest::class => ['old_json', 'review_note_ar'],
    ];

    /**
     * Columns that cannot be null and so take a marker instead of nothing. A
     * screen still has something to render and a name is still gone.
     *
     * @var array<class-string<Model>,array<int,string>>
     */
    public const MARKED = [
        Beneficiary::class => ['first_name', 'father_name', 'family_name', 'national_id_encrypted'],
        HouseholdMember::class => ['name_ar'],
        Caregiver::class => ['name_ar'],
        Debt::class => ['creditor_name_ar'],
        Post::class => ['title_ar'],
        Campaign::class => ['title_ar'],
        // These cannot be null either, so they take the marker too.
        Income::class => ['source_type'],
        JobProfile::class => ['summary_ar'],
        Complaint::class => ['subject_ar'],
        ChangeRequest::class => ['reason_ar'],
    ];

    /**
     * JSON columns that cannot be null, emptied rather than marked so the
     * column still holds valid JSON.
     *
     * change_requests.payload_json is the one that matters: it holds the very
     * value a change moved a field to, so a request to change a telephone
     * number carries that number in it.
     *
     * @var array<class-string<Model>,array<int,string>>
     */
    public const EMPTIED = [
        ChangeRequest::class => ['payload_json'],
    ];

    /**
     * Columns that cannot be null and must not keep a value anybody could
     * match on, so they take a fresh random token instead of a marker.
     *
     * national_id_hash is the whole reason this category exists: it is an
     * HMAC of the identity number under the application key, so anyone holding
     * the key and a list of candidate numbers could put the name back. It also
     * carries a unique index, so every anonymised file needs its own token.
     *
     * @var array<class-string<Model>,array<int,string>>
     */
    public const SCRAMBLED = [
        Beneficiary::class => ['national_id_hash'],
    ];

    /** The relation on the beneficiary that reaches each record. */
    private const RELATIONS = [
        HouseholdMember::class => 'members',
        Housing::class => 'housing',
        HealthRecord::class => 'healthRecords',
        Debt::class => 'debts',
        SupportSource::class => 'supportSources',
        Caregiver::class => 'caregivers',
        Asset::class => 'assets',
        Visit::class => 'visits',
        Income::class => 'incomes',
        Delivery::class => 'deliveries',
        Disbursement::class => 'disbursements',
        JobProfile::class => 'jobProfile',
    ];

    /**
     * The rest reach the family by a plain beneficiary_id with no relation on
     * the model, so they are swept by that column.
     *
     * @var array<class-string<Model>,string>
     */
    private const BY_COLUMN = [
        DistributionItem::class => 'beneficiary_id',
        Referral::class => 'beneficiary_id',
        Post::class => 'beneficiary_id',
        Campaign::class => 'beneficiary_id',
        ChangeRequest::class => 'beneficiary_id',
    ];

    /**
     * Every column that points at a Media row. The family's papers are not all
     * owned by the family: their medical report hangs off a health record,
     * their signed consent off a post, and the photograph of them receiving aid
     * off a delivery. Each of those files is as much the person as the identity
     * card is.
     *
     * @var array<class-string<Model>,array<int,string>>
     */
    private const MEDIA_COLUMNS = [
        HealthRecord::class => ['evidence_media_id'],
        Delivery::class => ['proof_media_id'],
        Disbursement::class => ['proof_media_id'],
        DistributionItem::class => ['proof_media_id'],
        Referral::class => ['proof_media_id'],
        Post::class => ['consent_media_id'],
    ];

    /** Public content about this family comes down as well as being cleared. */
    private const UNPUBLISHED = [
        Post::class => ['status' => 'hidden', 'is_published' => false, 'published_at' => null],
        Campaign::class => ['status' => 'hidden', 'is_published' => false],
        JobProfile::class => ['status' => 'hidden'],
    ];

    public function isAuthorised(User $user, string $permission): bool
    {
        if (! $user->can_($permission)) {
            return false;
        }

        if ($user->hasRole(...self::AUTHORISED_ROLES)) {
            return true;
        }

        return $user->hasRole('admin') && self::adminIsAllowed();
    }

    /** Whether the administrator's door is open right now. Data, not code. */
    public static function adminIsAllowed(): bool
    {
        return (bool) Setting::value(
            self::ADMIN_SWITCH,
            config('sanabel.setting_defaults.'.self::ADMIN_SWITCH)
        );
    }

    /** The statutory period, in years. Data, so the association moves it. */
    public static function retentionYears(): int
    {
        return (int) Setting::value('anonymise_after_years', config('sanabel.setting_defaults.anonymise_after_years'));
    }

    /**
     * When the file was last part of the association's work: the last payment
     * out, the last assessment, the day it was published or approved, or
     * failing all of those the day it was opened.
     *
     * It is deliberately not `updated_at`: a clerk correcting a spelling would
     * otherwise push a family's retention five years out, and a bulk edit
     * would push every family's.
     */
    public function lastActivityAt(Beneficiary $case): Carbon
    {
        $dates = [
            $case->disbursements()->whereNotNull('executed_at')->max('executed_at'),
            $case->last_assessment_at,
            $case->published_at,
            $case->approved_at,
            $case->created_at,
        ];

        $dates = array_values(array_filter(array_map(
            fn ($date) => $date ? Carbon::parse($date) : null,
            $dates
        )));

        return collect($dates)->max() ?? now();
    }

    /** The day the file's personal data may be destroyed, and not before. */
    public function retentionRunsOutAt(Beneficiary $case): Carbon
    {
        return $this->lastActivityAt($case)->copy()->addYears(self::retentionYears());
    }

    public function retentionHasRunOut(Beneficiary $case): bool
    {
        return $this->retentionRunsOutAt($case)->isPast();
    }

    /**
     * One person asks, and says why the law requires it.
     *
     * Two gates, both of them the association's own words of 12 October: the
     * personal data may be destroyed «عندما يوجب القانون ذلك وبعد انتهاء مدة
     * الاحتفاظ النظامية» — so a legal basis in writing, and a period that has
     * actually run out. And they named who may ask: the chairman of the board
     * of trustees and the executive director, nobody else.
     */
    public function request(Beneficiary $case, User $requester, string $reasonAr): Beneficiary
    {
        if ($case->isAnonymised()) {
            throw new \RuntimeException(__('sanabel.anonymise.already_done'));
        }

        if (! $this->isAuthorised($requester, 'request_anonymise')) {
            throw new \RuntimeException(__('sanabel.anonymise.not_authorised'));
        }

        if (trim($reasonAr) === '') {
            throw new \RuntimeException(__('sanabel.anonymise.reason_required'));
        }

        if (! $this->retentionHasRunOut($case)) {
            throw new \RuntimeException(__('sanabel.anonymise.retention_not_over', [
                'years' => self::retentionYears(),
                'date' => $this->retentionRunsOutAt($case)->toDateString(),
            ]));
        }

        $case->forceFill([
            'anonymise_requested_by' => $requester->getKey(),
            'anonymise_requested_at' => now(),
            'anonymise_reason_ar' => $reasonAr,
        ])->save();

        return $case->refresh();
    }

    /**
     * Another person carries it out. It cannot be undone, so the person who
     * asked is not the person who does it — the same rule as a disbursement.
     */
    public function execute(Beneficiary $case, User $actor): Beneficiary
    {
        if ($case->isAnonymised()) {
            throw new \RuntimeException(__('sanabel.anonymise.already_done'));
        }

        if (! $this->isAuthorised($actor, 'execute_anonymise')) {
            throw new \RuntimeException(__('sanabel.anonymise.not_authorised'));
        }

        if (! $case->anonymise_requested_by) {
            throw new \RuntimeException(__('sanabel.anonymise.not_requested'));
        }

        if ((int) $case->anonymise_requested_by === (int) $actor->getKey()) {
            throw new \RuntimeException(__('sanabel.anonymise.needs_another_person'));
        }

        return DB::transaction(function () use ($case, $actor) {
            $cleared = [];

            $cleared['beneficiary'] = $this->sweep($case);

            foreach (self::RELATIONS as $class => $relation) {
                $rows = collect($case->{$relation}()->get());

                foreach ($rows as $row) {
                    $this->sweep($row);
                }

                if ($rows->isNotEmpty()) {
                    $cleared[$relation] = $rows->count();
                }
            }

            // The ones with no relation on the model, reached by their column.
            foreach (self::BY_COLUMN as $class => $column) {
                $rows = $class::withoutGlobalScopes()->where($column, $case->getKey())->get();

                foreach ($rows as $row) {
                    $this->sweep($row);
                }

                if ($rows->isNotEmpty()) {
                    $cleared[class_basename($class)] = $rows->count();
                }
            }

            // A complaint carries no beneficiary_id: it reaches the family
            // through the account they filed it from.
            $cleared['complaints'] = $this->sweepComplaints($case);

            $cleared['media'] = $this->destroyPapers($case);
            $cleared['account'] = $this->closeAccount($case) ? 1 : 0;
            $cleared['audit_rows'] = $this->redactAuditHistory($case);

            // The wallet guard of T-59 is not the obstacle here: this is the
            // law being carried out, not an edit to where money is sent.
            Beneficiary::applyingApprovedChange(fn () => $case->forceFill([
                'anonymised_by' => $actor->getKey(),
                'anonymised_at' => now(),
            ])->save());

            /*
             | One audit row for the act itself, naming what was cleared and
             | how many rows — never a value. Rule 10, and more plainly: an
             | audit row carrying the data it just destroyed would make the
             | whole thing pointless.
             */
            AuditLog::create([
                'actor_id' => $actor->getKey(),
                'actor_role' => $actor->role?->key,
                'action' => 'anonymised',
                'entity_type' => Beneficiary::class,
                'entity_id' => $case->getKey(),
                'before_json' => null,
                'after_json' => [
                    'requested_by' => $case->anonymise_requested_by,
                    'reason_ar' => $case->anonymise_reason_ar,
                    'cleared' => $cleared,
                ],
            ]);

            return $case->refresh();
        });
    }

    /** @return array<int,string> the columns this row gave up */
    private function sweep(Model $row): array
    {
        $marker = __('sanabel.anonymise.marker');
        $changes = [];

        foreach (self::CLEARED[$row::class] ?? [] as $column) {
            if (! array_key_exists($column, $row->getAttributes())) {
                continue;
            }

            $changes[$column] = null;
        }

        foreach (self::MARKED[$row::class] ?? [] as $column) {
            if (array_key_exists($column, $row->getAttributes())) {
                $changes[$column] = $marker;
            }
        }

        foreach (self::EMPTIED[$row::class] ?? [] as $column) {
            if (array_key_exists($column, $row->getAttributes())) {
                $changes[$column] = [];
            }
        }

        foreach (self::SCRAMBLED[$row::class] ?? [] as $column) {
            if (array_key_exists($column, $row->getAttributes())) {
                $changes[$column] = 'anonymised:'.bin2hex(random_bytes(16));
            }
        }

        /*
         | Public content about this family comes down, not just emptied. A
         | published story with its title and body cleared is still a published
         | story, and a campaign still collecting money for a family whose file
         | has been erased has nobody to pay.
         */
        foreach (self::UNPUBLISHED[$row::class] ?? [] as $column => $value) {
            if (array_key_exists($column, $row->getAttributes())) {
                $changes[$column] = $value;
            }
        }

        if ($changes === []) {
            return [];
        }

        // forceFill and saveQuietly: these columns are not all fillable, and
        // the ordinary audit hook would write the old values into the log.
        $row->forceFill($changes)->saveQuietly();

        return array_keys($changes);
    }

    /** A complaint reaches the family through the account they filed it from. */
    private function sweepComplaints(Beneficiary $case): int
    {
        if (! $case->user_id) {
            return 0;
        }

        $rows = Complaint::withoutGlobalScopes()->where('submitted_by', $case->user_id)->get();

        foreach ($rows as $row) {
            $this->sweep($row);
        }

        return $rows->count();
    }

    /**
     * Every file that belongs to this family, wherever it hangs.
     *
     * Not all of them are owned by the beneficiary: the medical report hangs
     * off a health record, the signed consent off a post, the photograph of
     * them receiving aid off a delivery. Each of those is as much the person
     * as the identity card is, so the ids are collected from every column that
     * points at a Media row and the objects are deleted off the disk.
     *
     * The rows are archived rather than erased (rule 3) and serve nothing
     * afterwards.
     */
    private function destroyPapers(Beneficiary $case): int
    {
        $disk = Storage::disk(config('sanabel.media_disk'));
        $count = 0;

        // Owned by the family directly.
        $ids = Media::withTrashed()
            ->where('owner_type', Beneficiary::class)
            ->where('owner_id', $case->getKey())
            ->pluck('id');

        // And referenced from anything hanging off the family.
        foreach (self::MEDIA_COLUMNS as $class => $columns) {
            $query = array_key_exists($class, self::RELATIONS)
                ? $case->{self::RELATIONS[$class]}()->getQuery()
                : $class::withoutGlobalScopes()->where(self::BY_COLUMN[$class], $case->getKey());

            foreach ($columns as $column) {
                $ids = $ids->merge(
                    (clone $query)->whereNotNull($column)->pluck($column)
                );
            }
        }

        // Media the satellite records own in their own right.
        foreach (array_keys(self::MEDIA_COLUMNS) as $class) {
            $ownerIds = array_key_exists($class, self::RELATIONS)
                ? $case->{self::RELATIONS[$class]}()->pluck('id')
                : $class::withoutGlobalScopes()->where(self::BY_COLUMN[$class], $case->getKey())->pluck('id');

            if ($ownerIds->isEmpty()) {
                continue;
            }

            $ids = $ids->merge(
                Media::withTrashed()->where('owner_type', $class)->whereIn('owner_id', $ownerIds)->pluck('id')
            );
        }

        foreach (Media::withTrashed()->whereIn('id', $ids->unique()->filter()->all())->get() as $paper) {
            if ($paper->storage_key && $disk->exists($paper->storage_key)) {
                $disk->delete($paper->storage_key);
            }

            $paper->forceFill([
                'storage_key' => '',
                'mime' => null,
                'scan_status' => Media::REJECTED,
                'scan_note' => __('sanabel.anonymise.paper_destroyed'),
            ])->saveQuietly();

            if (! $paper->trashed()) {
                $paper->delete();
            }

            $count++;
        }

        return $count;
    }

    /** Their own portal account identifies them as surely as the file does. */
    private function closeAccount(Beneficiary $case): bool
    {
        $account = $case->user;

        if (! $account) {
            return false;
        }

        $account->forceFill([
            'name' => __('sanabel.anonymise.marker'),
            'email' => 'anonymised+'.$account->getKey().'@invalid.local',
            'password' => bcrypt(bin2hex(random_bytes(16))),
            'remember_token' => null,
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
            'is_active' => false,
        ])->saveQuietly();

        return true;
    }

    /**
     * The audit trail stays — they asked for that explicitly — but the values
     * inside it do not.
     *
     * Every past edit to a name was logged with the name in it, so leaving the
     * rows untouched would leave the family's name in the system after it was
     * supposed to be destroyed. The rows themselves, with who did what and
     * when, are what the association keeps; the payloads are rewritten to say
     * a value was redacted.
     *
     * Written through the query builder on purpose. AuditLog refuses an update
     * through Eloquent, and that guard stays exactly as it is for every other
     * path in the application: this is the one exception, it exists because
     * the law requires it, and the act of taking it is itself audited above.
     */
    private function redactAuditHistory(Beneficiary $case): int
    {
        $entities = [[Beneficiary::class, [$case->getKey()]]];

        foreach (self::RELATIONS as $class => $relation) {
            $ids = $case->{$relation}()->pluck('id')->all();

            if ($ids !== []) {
                $entities[] = [$class, $ids];
            }
        }

        foreach (self::BY_COLUMN as $class => $column) {
            $ids = $class::withoutGlobalScopes()->where($column, $case->getKey())->pluck('id')->all();

            if ($ids !== []) {
                $entities[] = [$class, $ids];
            }
        }

        $sensitive = [];

        foreach ([self::CLEARED, self::MARKED, self::SCRAMBLED, self::EMPTIED] as $group) {
            foreach ($group as $class => $columns) {
                $sensitive[$class] = array_merge($sensitive[$class] ?? [], $columns);
            }
        }

        $touched = 0;

        foreach ($entities as [$class, $ids]) {
            $keys = $sensitive[$class] ?? [];

            if ($keys === []) {
                continue;
            }

            $rows = DB::table('audit_log')
                ->where('entity_type', $class)
                ->whereIn('entity_id', $ids)
                ->get(['id', 'before_json', 'after_json']);

            foreach ($rows as $row) {
                $before = $this->redactPayload($row->before_json, $keys);
                $after = $this->redactPayload($row->after_json, $keys);

                if ($before === $row->before_json && $after === $row->after_json) {
                    continue;
                }

                DB::table('audit_log')->where('id', $row->id)
                    ->update(['before_json' => $before, 'after_json' => $after]);

                $touched++;
            }
        }

        return $touched;
    }

    private function redactPayload(?string $json, array $keys): ?string
    {
        if ($json === null) {
            return null;
        }

        $payload = json_decode($json, true);

        if (! is_array($payload)) {
            return $json;
        }

        foreach ($keys as $key) {
            if (array_key_exists($key, $payload)) {
                $payload[$key] = '[redacted]';
            }
        }

        return json_encode($payload, JSON_UNESCAPED_UNICODE);
    }
}
