<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Services\PermissionService;
use Illuminate\Database\Seeder;

/**
 * docs/04-permissions.md, plus the two launch roles the phases document names
 * separately: basic finance and content/media. Roles are data, so adding one
 * is an insert, not a rewrite.
 */
class RoleAndPermissionSeeder extends Seeder
{
    /** role key => [permission key => scope]. Mirrors the matrix in docs/04-permissions.md. */
    private const MATRIX = [
        'beneficiary' => [
            'view_full_case' => 'own',
            'publish_job_profile' => 'own',
            'file_complaint' => 'own',
        ],
        'delegate' => [
            'create_case' => 'all', 'edit_draft' => 'own', 'upload_media' => 'all',
            'record_visit' => 'area', 'recommend' => 'area', 'view_full_case' => 'area',
            'search_by_national_id' => 'area', 'request_change' => 'area',
            // No confirm_delivery: the association decided on 4 Oct 2026 that a
            // delegate verifies and studies, and touches no money — not the
            // transfer, and not the receipt that closes a case.
            'publish_job_profile' => 'area',
            'file_complaint' => 'own', 'view_reports' => 'own',
        ],
        'area_supervisor' => [
            'create_case' => 'area', 'edit_draft' => 'area', 'upload_media' => 'area',
            'record_visit' => 'area', 'recommend' => 'area', 'view_full_case' => 'area',
            'search_by_national_id' => 'area', 'request_change' => 'area',
            'confirm_delivery' => 'area', 'file_complaint' => 'own', 'view_reports' => 'area',
        ],
        'case_officer' => [
            'confirm_disbursement' => 'all',
            'create_case' => 'all', 'edit_draft' => 'all', 'upload_media' => 'all',
            'recommend' => 'all', 'view_full_case' => 'all', 'search_by_national_id' => 'all',
            'request_change' => 'all', 'confirm_delivery' => 'all', 'publish_job_profile' => 'all',
            // T-145 — the health path's cards, which no account held.
            'issue_referral' => 'all',
            'file_complaint' => 'own', 'view_reports' => 'all',
        ],
        // A partner association stands in for the delegate on the files it
        // raises: it enters the data and signs the field step off itself, and
        // the area supervisor then reviews it like any other file. So it holds
        // `recommend`, scoped to its own cases — never to anyone else's.
        'association' => [
            'create_case' => 'own', 'edit_draft' => 'own', 'upload_media' => 'own',
            'view_full_case' => 'own', 'search_by_national_id' => 'own', 'request_change' => 'own',
            'recommend' => 'own', 'confirm_delivery' => 'own', 'publish_job_profile' => 'own',
            'file_complaint' => 'own', 'view_reports' => 'own',
        ],
        'donor' => [
            'donate' => 'own', 'view_masked_case' => 'all', 'browse_job_market' => 'all',
            'file_complaint' => 'own', 'view_reports' => 'own',
        ],
        'service_provider' => [
            'manage_own_offers' => 'own', 'verify_referral' => 'own', 'confirm_delivery' => 'own',
            'file_complaint' => 'own', 'view_reports' => 'own',
        ],
        /*
         | 16 Oct 2026 — «نحتاج مدير النظام فقط صلاحيات تجهيز النظام فقط».
         |
         | It used to hold every key in the matrix, on the reading that a
         | technical account needs to be able to unstick anything. The
         | association corrected that: preparing the system and deciding
         | inside it are two different jobs, and the account that can grant
         | itself a permission must not be an account whose permissions
         | decide anything.
         |
         | So the row is written out, and PermissionService refuses the rest
         | in code as well — because the one key this role does keep is the
         | key that edits rows like this one.
         |
         | The two anonymisation keys stay by their own decision of 12
         | October: a switch that is closed by default, and a second person
         | to carry it out.
         */
        'admin' => [
            'manage_users' => 'all', 'edit_config' => 'all',
            'request_anonymise' => 'all', 'execute_anonymise' => 'all',
            'view_full_case' => 'all', 'view_masked_case' => 'all',
            'search_by_national_id' => 'all', 'browse_job_market' => 'all', 'view_reports' => 'all',
        ],
        // Money only. Verifying a transfer needs the file number and the
        // amount, never the family behind it, so this role reads the masked
        // case and never the full one.
        'finance' => [
            'reconcile_disbursement' => 'all',
            'verify_payment' => 'all', 'view_masked_case' => 'all',
            'view_reports' => 'all', 'file_complaint' => 'own',
        ],
        // Publishes news, pages and banners. Deliberately has no
        // view_full_case and no upload_media: managing content must not open
        // a family's documents.
        'content_manager' => [
            'manage_cms' => 'all', 'view_masked_case' => 'all',
            'file_complaint' => 'own',
        ],
        // Hard rule 1 — the board of trustees is read-only. It holds read
        // permissions only, and PermissionService denies every write key
        // regardless of what is stored. (The association confirmed on 6 Oct
        // that there is no separate board of directors: the trustees are the
        // board, and they look without touching.)
        'council' => [
            'view_full_case' => 'all', 'view_masked_case' => 'all',
            'search_by_national_id' => 'all', 'browse_job_market' => 'all', 'view_reports' => 'all',
        ],

        /*
         | The structure the association set out on 5 and 6 October. Roles are
         | rows, so this block is data: what each one may do, and nothing about
         | how the screens are built.
         */

        // The highest account there is. Separate from `admin`, which stays the
        // technical account and is still barred from opening a family file.
        'board_director' => 'all',

        // 13 Oct 2026 — «مدير مجلس الأمناء ونائبه كامل البيانات». The deputy
        // holds the same keys under their own name, as the deputy executive
        // director does: a deputy is an account, not a shared login.
        'deputy_board_director' => 'all',

        // The administrative sign-off at the end of the path. Reads widely,
        // approves, and touches neither the data entry nor the execution.
        'executive_director' => [
            'approve_case' => 'all', 'approve_change' => 'all', 'suspend_graduate' => 'all',
            'view_full_case' => 'all', 'view_masked_case' => 'all', 'search_by_national_id' => 'all',
            'handle_complaint' => 'all', 'manage_campaigns' => 'all', 'approve_content' => 'all',
            'approve_disbursement' => 'all',
            // T-145 — the health path: the cards and the askings before them.
            'issue_referral' => 'all',
            /*
             | T-105 — mass distributions and the allocation of general money
             | to families. Both were the administrator's screens, and both
             | are decisions about who gets paid what, so the instruction of
             | 16 October took them off that account. Nobody held the key but
             | the chairman, which would have left two working screens with
             | one person in the building able to open them.
             |
             | Placed here because every other operational approval already
             | is: the file, the change, the campaign, the payment. If the
             | association would rather the treasurer ran the distributions,
             | it is this line that moves.
             */
            'manage_distribution' => 'all',
            'file_complaint' => 'own', 'view_reports' => 'all',
            // 12 Oct 2026 — anonymisation is theirs and the chairman's, nobody
            // else's. The chairman holds every key, so this is the other half
            // of the pair, and the two-person rule makes them each other's.
            'request_anonymise' => 'all', 'execute_anonymise' => 'all',
            /*
             | 13 Oct 2026 — «مع امكانية تحديد الصلاحيات واظهار البيانات من قبل
             | المدير التنفيذي». The settings screen is where «إظهار البيانات»
             | is decided: the funding share, and the switches for what a donor
             | sees.
             |
             | It also repairs a contradiction of our own making. T-75 put the
             | administrator's anonymisation door in the hands of the two
             | directors and their deputies — and the page itself was open to
             | `edit_config` alone, so the account that holds the key could not
             | reach the lock. Found by the training capture, which opened the
             | screen as the director and was answered 403.
             */
            'edit_config' => 'all',
        ],

        // Same powers, own account, own name on every action. The time-bounded
        // delegation the association asked about is phase two; today a deputy
        // simply holds the role.
        'deputy_executive_director' => [
            'approve_case' => 'all', 'approve_change' => 'all', 'suspend_graduate' => 'all',
            'view_full_case' => 'all', 'view_masked_case' => 'all', 'search_by_national_id' => 'all',
            'handle_complaint' => 'all', 'manage_campaigns' => 'all', 'approve_content' => 'all',
            'approve_disbursement' => 'all', 'manage_distribution' => 'all',
            'file_complaint' => 'own', 'view_reports' => 'all',
            // 13 Oct 2026 — the authorised became four: both directors and
            // both deputies.
            'request_anonymise' => 'all', 'execute_anonymise' => 'all',
            // And the deputy holds the same key to the settings screen, for
            // the same reason: a deputy is an account, not a shared login.
            'edit_config' => 'all',
        ],

        'deputy_area_supervisor' => [
            'create_case' => 'area', 'edit_draft' => 'area', 'upload_media' => 'area',
            'record_visit' => 'area', 'recommend' => 'area', 'view_full_case' => 'area',
            'search_by_national_id' => 'area', 'request_change' => 'area',
            'confirm_delivery' => 'area', 'file_complaint' => 'own', 'view_reports' => 'area',
        ],

        // Money out: raises the disbursement and executes it once approved.
        // Reads the masked case only — paying a family needs the file number
        // and the amount, never the household behind them.
        'treasurer' => [
            'raise_disbursement_order' => 'all', 'execute_disbursement' => 'all',
            'verify_payment' => 'all', 'view_masked_case' => 'all',
            'view_reports' => 'all', 'file_complaint' => 'own',
        ],

        // Oversight: sees everything, changes nothing. is_read_only below is
        // what enforces that, so no stored grant can make this role write.
        'oversight_director' => [
            'view_full_case' => 'all', 'view_masked_case' => 'all',
            'search_by_national_id' => 'all', 'view_reports' => 'all',
        ],

        // Enters and completes, never approves.
        'data_officer' => [
            'edit_draft' => 'all', 'upload_media' => 'all', 'view_full_case' => 'all',
            'search_by_national_id' => 'all', 'request_change' => 'all',
            'file_complaint' => 'own', 'view_reports' => 'own',
        ],

        // Checks the entry: completeness, duplicates, sending a file back.
        /*
         | T-143 — «صفحة مسؤول البيانات: لما تجي التبرعات لعنده يقدر يعمل طلب
         | صرف ويطلع لامين الصندوق, ويتم بعدين يطلع عند المدير التنفيذي ويعمل
         | موافقة, ويرجع لامين الصندوق ويعمل التحويل», 28 October.
         |
         | The first step of the money path — «this family is owed this much
         | this month» — was the case officer's and the data manager's. The
         | association put it in this account's hands as well; the four steps
         | after it do not move, and no account holds two of them.
         */
        'data_supervisor' => [
            'confirm_disbursement' => 'all',
            'create_case' => 'all', 'edit_draft' => 'all', 'upload_media' => 'all',
            'merge_duplicates' => 'all', 'view_full_case' => 'all', 'search_by_national_id' => 'all',
            'request_change' => 'all', 'approve_change' => 'all',
            'file_complaint' => 'own', 'view_reports' => 'all',
        ],

        // Over the data system as a whole, and out of the assessment and the money.
        'data_manager' => [
            'confirm_disbursement' => 'all', 'edit_draft' => 'all', 'merge_duplicates' => 'all', 'view_full_case' => 'all',
            'view_masked_case' => 'all', 'search_by_national_id' => 'all',
            'approve_change' => 'all', 'file_complaint' => 'own', 'view_reports' => 'all',
        ],
    ];

    /** Roles that may look but never write, whatever the matrix says. */
    private const READ_ONLY = ['council', 'oversight_director'];

    private const NAMES = [
        'beneficiary' => 'مستفيد', 'delegate' => 'مندوب', 'area_supervisor' => 'مشرف المناديب',
        'case_officer' => 'مسؤول الحالات', 'association' => 'جمعية', 'donor' => 'متبرع',
        'service_provider' => 'مزود خدمة', 'admin' => 'مدير النظام', 'council' => 'مجلس الأمناء',
        'finance' => 'المحاسب', 'content_manager' => 'مدير المحتوى',
        'board_director' => 'مدير مجلس الأمناء',
        'deputy_board_director' => 'نائب مدير مجلس الأمناء',
        'executive_director' => 'المدير التنفيذي',
        'deputy_executive_director' => 'نائب المدير التنفيذي',
        'deputy_area_supervisor' => 'نائب مشرف المناديب',
        'treasurer' => 'أمين الصندوق',
        'oversight_director' => 'مدير لجنة الرقابة والتفتيش',
        'data_officer' => 'موظف البيانات',
        'data_supervisor' => 'مسؤول البيانات',
        'data_manager' => 'مدير البيانات',
    ];

    public function run(): void
    {
        foreach (PermissionService::permissionKeys() as $key) {
            /*
             | T-145 — updateOrCreate, not firstOrCreate: a key seeded before
             | its Arabic name existed kept the untranslated placeholder as
             | its name for ever, and the permission matrix printed it.
             */
            Permission::updateOrCreate(['key' => $key], ['name_ar' => __('sanabel.permissions.keys.'.$key)]);
        }

        $permissions = Permission::pluck('id', 'key');

        foreach (self::MATRIX as $roleKey => $grants) {
            $role = Role::updateOrCreate(
                ['key' => $roleKey],
                ['name_ar' => self::NAMES[$roleKey], 'is_read_only' => in_array($roleKey, self::READ_ONLY, true)],
            );

            $sync = $grants === 'all'
                ? $permissions->mapWithKeys(fn ($id) => [$id => ['scope' => 'all']])->all()
                : collect($grants)
                    ->mapWithKeys(fn ($scope, $key) => [$permissions[$key] => ['scope' => $scope]])
                    ->all();

            $role->permissions()->sync($sync);
        }
    }
}
