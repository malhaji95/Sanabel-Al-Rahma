<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RoleAndPermissionSeeder::class,
            FundSeeder::class,
            SettingSeeder::class,
            RegionSeeder::class,
            ReferenceValueSeeder::class,
            PrivacyPolicySeeder::class,
            // T-89 — the home page and the bars around it, as rows the
            // association edits. Idempotent, so it never overwrites an edit.
            HomePageSeeder::class,
            AdminUserSeeder::class,
        ]);
    }
}
