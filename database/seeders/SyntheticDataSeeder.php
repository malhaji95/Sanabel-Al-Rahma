<?php

namespace Database\Seeders;

use App\Models\Beneficiary;
use App\Models\Donor;
use App\Models\HealthRecord;
use App\Models\HouseholdMember;
use App\Models\Housing;
use App\Models\Income;
use App\Models\Page;
use App\Models\Post;
use App\Models\Provider;
use App\Models\Region;
use App\Models\Role;
use App\Models\User;
use App\Services\AssessmentService;
use App\Services\DependencyRules;
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

                HouseholdMember::create([
                    'beneficiary_id' => $case->id,
                    'relation' => $class,
                    'name_ar' => "فرد {$class} {$n}",
                    'birth_year' => $birthYear,
                    'gender' => $n % 2 === 0 ? 'female' : 'male',
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
