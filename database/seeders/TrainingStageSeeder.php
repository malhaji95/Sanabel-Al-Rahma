<?php

namespace Database\Seeders;

use App\Models\Beneficiary;
use App\Models\Region;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * T-72 — the data the training tour photographs.
 *
 * A guide that follows a role's working order has to show that role's screens
 * with something on them: the area supervisor's endorsement screen is empty
 * until a file is standing at endorsement, and a guide made of empty screens
 * teaches nothing. So rather than drive the forms with a browser, which breaks
 * whenever a field moves, this leaves one file standing at each stage of the
 * path and the tour simply photographs them.
 *
 * It also opens the accounts the structure names but the demo did not: the
 * executive director, the treasurer, the data officer, and a household with an
 * account of its own. Synthetic throughout — rule 11.
 */
class TrainingStageSeeder extends Seeder
{
    public function run(): void
    {
        $area = Region::where('type', 'area')->orderBy('id')->first();

        foreach ([
            ['executive_director', 'director@sanabel.local', 'المدير التنفيذي', null],
            ['board_director', 'chairman@sanabel.local', 'رئيس مجلس الأمناء', null],
            ['treasurer', 'treasurer@sanabel.local', 'أمين الصندوق', null],
            ['data_officer', 'data@sanabel.local', 'مدخل بيانات', $area?->id],
        ] as [$roleKey, $email, $name, $regionId]) {
            User::firstOrCreate(['email' => $email], [
                'name' => $name,
                'password' => Hash::make(env('SEED_DEMO_PASSWORD', 'password')),
                'role_id' => Role::where('key', $roleKey)->value('id'),
                'region_id' => $regionId,
                'is_active' => true,
            ]);
        }

        $this->stageThePath($village);
        $this->giveAHouseholdItsOwnAccount();
        $this->enrolTheSecondFactor();

        $this->command?->info('Training stages seeded. Synthetic families only.');
    }

    /**
     * One file left standing at each step, so every role in the path has a
     * screen with work on it. The dates run backwards from today, the way a
     * real queue looks: the oldest waiting longest.
     */
    private function stageThePath($area): void
    {
        $delegate = User::where('email', 'delegate@sanabel.local')->first();
        $supervisor = User::where('email', 'supervisor@sanabel.local')->first();

        $stages = [
            // Waiting for the delegate to go and see the family.
            ['pending_visit', ['created_by' => $delegate?->getKey()]],
            // Seen and written up, waiting for the area supervisor to endorse.
            ['verified', [
                'created_by' => $delegate?->getKey(),
                'field_verified_at' => now()->subDays(3),
                'field_verified_by' => $delegate?->getKey(),
            ]],
            // Endorsed, waiting for the administrative approval.
            ['pending_approval', [
                'created_by' => $delegate?->getKey(),
                'field_verified_at' => now()->subDays(6),
                'field_verified_by' => $delegate?->getKey(),
                'endorsed_at' => now()->subDays(2),
                'endorsed_by' => $supervisor?->getKey(),
            ]],
        ];

        foreach ($stages as $index => [$status, $extra]) {
            $existing = Beneficiary::where('status', $status)->exists();

            if ($existing) {
                continue;
            }

            $case = Beneficiary::where('status', 'draft')->orderBy('id')->skip($index)->first();

            if (! $case) {
                continue;
            }

            // In the delegate's and supervisor's own area, or their screens
            // answer 404 for it — which is the region scope working, and makes
            // the guide photograph an error page instead of their work.
            $case->forceFill(array_merge([
                'status' => $status,
                'region_id' => $area?->id ?? $case->region_id,
            ], $extra))->saveQuietly();
        }
    }

    /**
     * The roles that must pass a second factor cannot be photographed without
     * one. The secret comes from the environment and has no default, so no
     * shared secret lives in the repository; without it the tour skips those
     * roles and says so.
     */
    private function enrolTheSecondFactor(): void
    {
        $secret = env('SEED_DEMO_TOTP');

        if (! $secret) {
            return;
        }

        User::whereIn('role_id', Role::whereIn('key', User::TWO_FACTOR_ROLES)->pluck('id'))
            ->get()
            ->each(fn (User $user) => $user->forceFill([
                'two_factor_secret' => $secret,
                'two_factor_confirmed_at' => now(),
            ])->saveQuietly());
    }

    /** The household's own page needs a household with a login. */
    private function giveAHouseholdItsOwnAccount(): void
    {
        $user = User::firstOrCreate(['email' => 'family@sanabel.local'], [
            'name' => 'أسرة تجريبية',
            'password' => Hash::make(env('SEED_DEMO_PASSWORD', 'password')),
            'role_id' => Role::where('key', 'beneficiary')->value('id'),
            'is_active' => true,
        ]);

        // One household to one account: the column is unique, so a second run
        // must find the file it already linked rather than link another.
        $case = Beneficiary::where('user_id', $user->getKey())->first()
            ?? Beneficiary::where('status', 'published')->whereNull('user_id')->orderBy('id')->first();

        if (! $case) {
            return;
        }

        $user->forceFill(['region_id' => $case->region_id])->saveQuietly();

        $case->forceFill([
            'user_id' => $user->getKey(),
            // A number to show on their own page, so the screen the guide
            // photographs is the screen they will actually see.
            'wallet_encrypted' => $case->wallet_encrypted ?: '0933777888',
        ])->saveQuietly();
    }
}
