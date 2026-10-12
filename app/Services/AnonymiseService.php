<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Beneficiary;
use App\Models\Caregiver;
use App\Models\Debt;
use App\Models\HealthRecord;
use App\Models\HouseholdMember;
use App\Models\Housing;
use App\Models\Media;
use App\Models\SupportSource;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Database\Eloquent\Model;
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
            'town_ar', 'latitude', 'longitude', 'birth_date', 'workplace_ar', 'surveyed_by_ar',
            'previous_aid_ar', 'livelihood_proposal_ar', 'proposal_skills_ar', 'furnishing_ar',
            // The link back to the old system's archive, which still holds the
            // name. Leaving it would make this a redirection, not an erasure.
            'legacy_file_no', 'legacy_review_json',
        ],
        HouseholdMember::class => [
            'national_id_encrypted', 'relation_other_ar', 'birth_date', 'spouse_name_ar',
            'workplace_ar', 'school_grade_ar', 'certificate_ar', 'skills_ar', 'notes_ar',
        ],
        Housing::class => ['landlord_name_ar', 'landlord_phone_encrypted', 'type_other_ar'],
        HealthRecord::class => [
            'description_ar', 'treatment_place_ar', 'medicines_ar',
            'doctor_name_ar', 'doctor_phone_encrypted',
        ],
        Debt::class => ['creditor_address_ar', 'creditor_phone_encrypted', 'reason_ar'],
        // name_ar here is another association, not a person, so it stays.
        SupportSource::class => ['notes_ar'],
        Caregiver::class => [
            'national_id_encrypted', 'relation_other_ar', 'income_source_ar', 'notes_ar',
        ],
        Asset::class => ['kind_other_ar', 'necessity_reason_ar', 'details_ar'],
        Visit::class => ['note_ar', 'payload_json', 'conflict_reason'],
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
    ];

    /** One person asks, and says why the law requires it. */
    public function request(Beneficiary $case, User $requester, string $reasonAr): Beneficiary
    {
        if ($case->isAnonymised()) {
            throw new \RuntimeException(__('sanabel.anonymise.already_done'));
        }

        if (trim($reasonAr) === '') {
            throw new \RuntimeException(__('sanabel.anonymise.reason_required'));
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
                $rows = $case->{$relation}()->get();

                foreach ($rows as $row) {
                    $this->sweep($row);
                }

                if ($rows->isNotEmpty() && (self::CLEARED[$class] ?? self::MARKED[$class] ?? null)) {
                    $cleared[$relation] = $rows->count();
                }
            }

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

        foreach (self::SCRAMBLED[$row::class] ?? [] as $column) {
            if (array_key_exists($column, $row->getAttributes())) {
                $changes[$column] = 'anonymised:'.bin2hex(random_bytes(16));
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

    /**
     * The family's papers. The rows are archived rather than erased (rule 3),
     * but the objects themselves go: an identity card photograph is the
     * identity, and leaving the file on disk would make this a half-erasure.
     */
    private function destroyPapers(Beneficiary $case): int
    {
        $disk = Storage::disk(config('sanabel.media_disk'));
        $count = 0;

        foreach (Media::withTrashed()
            ->where('owner_type', Beneficiary::class)
            ->where('owner_id', $case->getKey())
            ->get() as $paper) {
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

        $sensitive = [];

        foreach ([self::CLEARED, self::MARKED, self::SCRAMBLED] as $group) {
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
