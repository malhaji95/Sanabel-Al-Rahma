<?php

namespace Database\Seeders;

use App\Models\Beneficiary;
use App\Models\Campaign;
use App\Models\Complaint;
use App\Models\Distribution;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\Fund;
use App\Models\HealthRecord;
use App\Models\HouseholdMember;
use App\Models\Housing;
use App\Models\Income;
use App\Models\JobProfile;
use App\Models\Member;
use App\Models\Page;
use App\Models\Post;
use App\Models\Provider;
use App\Models\Region;
use App\Models\Role;
use App\Models\User;
use App\Services\AssessmentService;
use App\Services\BasketService;
use App\Services\CaseService;
use App\Services\CoverageService;
use App\Services\DependencyRules;
use App\Services\DistributionService;
use App\Services\DonationService;
use App\Services\MembershipService;
use App\Services\ReferralService;
use App\Services\SponsorshipService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * T-40 — data for training and demos.
 *
 * Rule 11: no real data in dev or test. Every family here is generated;
 * the names are obviously synthetic and no national ID belongs to a person.
 */
class SyntheticDataSeeder extends Seeder
{
    private const FAMILY_NAMES = ['التجريبية', 'النموذجية', 'الافتراضية', 'التدريبية', 'الاختبارية'];

    /**
     * صلة القرابة is the kinship, which is what the form asks for. The age
     * class it used to be filled with already has its own column,
     * person_class, so the two were saying the same thing and the screen
     * showed 'adult' where it asks for a relation.
     *
     * @var array<string,array<string,string>>
     */
    private const RELATIONS = [
        'adult' => ['male' => 'زوج', 'female' => 'زوجة'],
        'child' => ['male' => 'ابن', 'female' => 'ابنة'],
        'elderly' => ['male' => 'والد', 'female' => 'والدة'],
    ];

    public function run(): void
    {
        $this->call([RoleAndPermissionSeeder::class, FundSeeder::class, SettingSeeder::class]);

        if (Region::count() === 0) {
            $this->call(RegionSeeder::class);
        }

        $this->call(ReferenceValueSeeder::class);

        $areas = Region::where('type', 'area')->get();

        if ($areas->isEmpty()) {
            $this->command?->warn('No areas seeded; skipping synthetic families.');

            return;
        }

        $staff = $this->staff($areas);
        $this->cms();
        $this->providers($areas);
        $this->donors();

        foreach (range(1, 40) as $i) {
            $this->family($i, $areas->random(), $staff);
        }

        $this->changeRequests($staff);
        $this->campaign();
        $this->money($staff);
        $this->services($staff);

        $this->command?->info('Synthetic data seeded. No real family data is present.');
    }

    /** @return array<string,User> */
    private function staff($areas): array
    {
        $make = function (string $roleKey, string $email, string $name, ?int $regionId = null) {
            return User::firstOrCreate(['email' => $email], [
                'name' => $name,
                'password' => Hash::make(env('SEED_DEMO_PASSWORD', 'password')),
                'role_id' => Role::where('key', $roleKey)->value('id'),
                'region_id' => $regionId,
                'is_active' => true,
            ]);
        };

        return [
            'admin' => $make('admin', 'admin@sanabel.local', 'مدير النظام'),
            'officer' => $make('case_officer', 'officer@sanabel.local', 'مسؤول الحالات'),
            'supervisor' => $make('area_supervisor', 'supervisor@sanabel.local', 'مشرف المنطقة', $areas->first()->id),
            'delegate' => $make('delegate', 'delegate@sanabel.local', 'مندوب ميداني', $areas->first()->id),
            'association' => $make('association', 'association@sanabel.local', 'جمعية شريكة', $areas->first()->id),
            'council' => $make('council', 'council@sanabel.local', 'عضو مجلس الإدارة'),
            'provider' => $make('service_provider', 'provider@sanabel.local', 'مركز طبي'),
            'donor' => $make('donor', 'donor@sanabel.local', 'متبرع تجريبي'),
            'finance' => $make('finance', 'finance@sanabel.local', 'مسؤول المالية'),
            'content' => $make('content_manager', 'content@sanabel.local', 'مسؤول المحتوى'),
        ];
    }

    private function family(int $index, Region $region, array $staff): void
    {
        $nationalId = '900'.str_pad((string) $index, 8, '0', STR_PAD_LEFT);

        $case = Beneficiary::create([
            'file_number' => 'DEMO-'.str_pad((string) $index, 4, '0', STR_PAD_LEFT),
            'national_id_encrypted' => $nationalId,
            'national_id_hash' => Beneficiary::hashNationalId($nationalId),
            'first_name' => 'أسرة',
            'father_name' => 'تجريبية',
            'family_name' => self::FAMILY_NAMES[$index % count(self::FAMILY_NAMES)].' '.$index,
            'phone_encrypted' => '0900'.str_pad((string) $index, 6, '0', STR_PAD_LEFT),
            'region_id' => $region->id,
            'marital_status' => $index % 4 === 0 ? 'widowed' : 'married',
            'support_type' => $index % 3 === 0 ? 'one_time' : 'monthly',
            'status' => 'draft',
            'source' => $index % 5 === 0 ? 'association' : 'delegate',
            'documented_debt' => $index % 6 === 0 ? 150_000 : 0,
            'urgency_deadline_at' => $index % 7 === 0 ? now()->addDays(20) : null,
            'created_by' => $staff['delegate']->id,
        ]);

        $adults = 1 + ($index % 2);
        $children = $index % 5;
        $elderly = $index % 8 === 0 ? 1 : 0;

        foreach ([['adult', $adults, 35], ['child', $children, 8], ['elderly', $elderly, 70]] as [$class, $count, $age]) {
            foreach (range(1, max(0, $count)) as $n) {
                if ($count === 0) {
                    continue;
                }

                $birthYear = (int) date('Y') - $age;
                $gender = $n % 2 === 0 ? 'female' : 'male';
                $relation = self::RELATIONS[$class][$gender];

                HouseholdMember::create([
                    'beneficiary_id' => $case->id,
                    'relation' => $relation,
                    'name_ar' => "{$relation} {$n}",
                    'birth_year' => $birthYear,
                    'gender' => $gender,
                    'person_class' => DependencyRules::personClass($age),
                    'dependent' => DependencyRules::isDependent($age, false, false),
                    'unable_to_earn' => false,
                ]);
            }
        }

        Income::create([
            'beneficiary_id' => $case->id,
            'source_type' => 'work',
            'amount' => $index % 3 === 0 ? 0 : 2_000 * ($index % 5),
            'currency' => config('sanabel.currency'),
            'is_stable' => $index % 3 !== 0,
        ]);

        $renting = $index % 2 === 0;

        Housing::create([
            'beneficiary_id' => $case->id,
            'housing_type' => $renting ? 'rent' : 'owned',
            'monthly_rent' => $renting ? 25_000 + 1_000 * ($index % 10) : 0,
            'currency' => config('sanabel.currency'),
            'habitable_rooms' => 1 + ($index % 3),
            'safety_band' => [0, 25, 50, 75][$index % 4],
            'services_band' => [0, 50, 100][$index % 3],
            'eviction_band' => $renting ? [0, 25, 50][$index % 3] : 0,
        ]);

        if ($index % 4 === 0) {
            HealthRecord::create([
                'beneficiary_id' => $case->id,
                'severity_band' => [25, 50, 75][$index % 3],
                'economic_impact_band' => [25, 50][$index % 2],
                'care_burden_band' => 25,
                'monthly_medical_cost' => 5_000,
                'currency' => config('sanabel.currency'),
                'description_ar' => 'حالة مزمنة (بيانات تجريبية)',
            ]);
        }

        // Most demo files are approved and published so the donor screens have content.
        if ($index % 6 !== 0) {
            $case->forceFill([
                'status' => 'published',
                'approved_by' => $staff['admin']->id,
                'approved_at' => now()->subDays($index),
                'published_at' => now()->subDays($index),
            ])->save();

            app(AssessmentService::class)->create($case->refresh(), status: 'approved');
        }
    }

    /**
     * A published case may not be edited straight through by anyone but an
     * admin, so the review queue is where every other edit lands. Seeding a
     * couple of pending ones gives the queue something to show.
     */
    private function changeRequests(array $staff): void
    {
        $cases = Beneficiary::where('status', 'published')->orderBy('id')->take(2)->get();

        if ($cases->count() < 2) {
            return;
        }

        // Material: the rent feeds the housing factor, so approving this
        // recomputes the assessment.
        app(CaseService::class)->requestChange(
            $cases[0],
            $staff['delegate'],
            ['monthly_rent' => 55_000],
            'ارتفع الإيجار بعد تجديد العقد.',
        );

        // Not material: a corrected phone number changes no score.
        app(CaseService::class)->requestChange(
            $cases[1],
            $staff['association'],
            ['phone_encrypted' => '0900999999'],
            'تصحيح رقم الهاتف بعد زيارة ميدانية.',
        );
    }

    /**
     * Walks the money path with the real services rather than writing rows, so
     * the demo shows what the code actually produces: verified transfers that
     * move coverage, one still waiting in the verification queue, a sponsorship
     * with its schedule, and a frozen distribution list.
     */
    private function money(array $staff): void
    {
        if (Donation::exists()) {
            return;
        }

        $cases = Beneficiary::where('status', 'published')->orderBy('id')->take(6)->get();
        $donors = Donor::orderBy('id')->take(3)->get();

        if ($cases->count() < 4 || $donors->count() < 2) {
            return;
        }

        $baskets = app(BasketService::class);
        $donations = app(DonationService::class);
        $coverage = app(CoverageService::class);

        // Two transfers that arrived and were verified, and one still pending.
        foreach ([[0, [0, 1]], [1, [2, 3]]] as $i => [$donorIndex, $caseIndexes]) {
            $basket = $baskets->openFor($donors[$donorIndex]);
            $total = 0;

            foreach ($caseIndexes as $caseIndex) {
                $case = $cases[$caseIndex];
                $amount = (int) floor($coverage->remainingNeed($case) * ($caseIndex === 0 ? 1 : 0.5));

                if ($amount > 0) {
                    $baskets->addItem($basket, $case, $amount);
                    $total += $amount;
                }
            }

            if ($total === 0) {
                continue;
            }

            $baskets->reserve($basket);

            $donation = $donations->record([
                'donor_id' => $donors[$donorIndex]->id,
                'basket_id' => $basket->id,
                'amount' => $total,
                'transaction_ref' => 'DEMO-TRX-'.str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT),
            ]);

            $donations->verify($donation, $staff['admin']->id);
        }

        // One waiting in the queue, so the verification screen is not empty.
        $pendingBasket = $baskets->openFor($donors[0]);
        $baskets->addItem($pendingBasket, $cases[4], 10_000);
        $baskets->reserve($pendingBasket);
        $donations->record([
            'donor_id' => $donors[0]->id,
            'basket_id' => $pendingBasket->id,
            'amount' => 10_000,
            'transaction_ref' => 'DEMO-TRX-0003',
        ]);

        app(SponsorshipService::class)->create([
            'donor_id' => $donors[1]->id,
            'beneficiary_id' => $cases[5]->id,
            'amount' => 25_000,
            'start_date' => now()->startOfMonth()->subMonths(2)->toDateString(),
            'end_date' => now()->startOfMonth()->addMonths(9)->toDateString(),
            'status' => 'active',
            'created_by' => $staff['admin']->id,
        ]);

        $distribution = Distribution::create([
            'region_id' => $cases[0]->region_id,
            'title_ar' => 'سلال غذائية — الدفعة الأولى',
            'total_amount' => 100_000,
            'per_family_amount' => 20_000,
            'currency' => config('sanabel.currency'),
            'criteria_json' => ['limit' => 5],
            'status' => 'draft',
            'created_by' => $staff['admin']->id,
        ]);

        // Generate, then approve: approval is what freezes the list and turns
        // it into the rows the executor works through.
        app(DistributionService::class)->generateList($distribution);
        app(DistributionService::class)->approve($distribution->refresh(), $staff['admin']);
    }

    /** The service modules: referral, job market, memberships, a complaint. */
    private function services(array $staff): void
    {
        if (Member::exists()) {
            return;
        }

        $cases = Beneficiary::where('status', 'published')->orderBy('id')->take(3)->get();
        $provider = Provider::orderBy('id')->first();

        if ($provider && $cases->isNotEmpty()) {
            app(ReferralService::class)->issue($cases[0], $provider);
        }

        foreach ($cases as $i => $case) {
            JobProfile::create([
                'beneficiary_id' => $case->id,
                'trade_key' => ['carpenter', 'tailor', 'electrician'][$i % 3],
                'summary_ar' => 'خبرة عملية سابقة، ويبحث عن عمل ضمن المنطقة.',
                'region_id' => $case->region_id,
                'availability' => 'full_time',
                'status' => 'published',
                'created_by' => $staff['delegate']->id,
            ]);
        }

        foreach ([['عضو عامل تجريبي', 'working'], ['عضو داعم تجريبي', 'supporting']] as $i => [$name, $category]) {
            $member = Member::create([
                'membership_no' => 'MEM-'.str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT),
                'name_ar' => $name,
                'category' => $category,
                'status' => 'active',
                'joined_at' => now()->subMonths(6)->toDateString(),
                'created_by' => $staff['admin']->id,
            ]);

            app(MembershipService::class)->generateSubscriptions(
                $member,
                now()->startOfMonth()->subMonths(3),
                now()->startOfMonth(),
                15_000,
            );
        }

        Complaint::create([
            'reference_no' => 'CMP-0001',
            'subject_ar' => 'تأخر في موعد التسليم',
            'body_ar' => 'تأخر تسليم المساعدة عن الموعد المتفق عليه بأسبوع.',
            'category' => 'service',
            'status' => 'assigned',
            'owner_id' => $staff['officer']->id,
            'created_by' => $staff['officer']->id,
        ]);
    }

    /** One published campaign, so the funding path has something to show. */
    private function campaign(): void
    {
        $case = Beneficiary::where('status', 'published')->orderBy('id')->first();

        if (! $case || Campaign::exists()) {
            return;
        }

        Campaign::create([
            'beneficiary_id' => $case->id,
            'title_ar' => 'عملية جراحية عاجلة',
            'body_ar' => 'تحتاج الأسرة إلى تغطية تكلفة عملية جراحية لا يشملها الدعم الشهري.',
            'goal_amount' => 100_000,
            'currency' => config('sanabel.currency'),
            'status' => 'active',
            'is_published' => true,
            'surplus_policy_text_ar' => 'يوجَّه الفائض إلى حملة صحية مماثلة في المنطقة نفسها.',
            'fund_id' => Fund::byKey(Fund::RESTRICTED)->id,
        ]);
    }

    private function donors(): void
    {
        Donor::firstOrCreate(['email' => 'donor@sanabel.local'], [
            'user_id' => User::where('email', 'donor@sanabel.local')->value('id'),
            'name_ar' => 'متبرع تجريبي',
            'phone_encrypted' => '0911111111',
        ]);

        foreach (range(1, 5) as $i) {
            Donor::firstOrCreate(['email' => "donor{$i}@sanabel.local"], [
                'name_ar' => "متبرع {$i}",
                'phone_encrypted' => '09222222'.$i,
            ]);
        }
    }

    private function providers($areas): void
    {
        Provider::firstOrCreate(['name_ar' => 'المركز الطبي التجريبي'], [
            'user_id' => User::where('email', 'provider@sanabel.local')->value('id'),
            'type' => 'clinic',
            'specialty_ar' => 'طب عام',
            'region_id' => $areas->first()->id,
            'discount_type' => 'percentage',
            'discount_value' => 25,
            'valid_until' => now()->addYear()->toDateString(),
            'status' => 'active',
        ]);
    }

    private function cms(): void
    {
        Page::firstOrCreate(['slug' => 'about'], [
            'title_ar' => 'من نحن',
            'body_ar' => 'صفحة تعريفية تجريبية يحررها المدير من لوحة التحكم.',
            'is_published' => true,
        ]);

        Post::firstOrCreate(['slug' => 'launch'], [
            'title_ar' => 'إطلاق المنصة',
            'body_ar' => 'خبر تجريبي.',
            'is_published' => true,
            'published_at' => now(),
        ]);
    }
}
