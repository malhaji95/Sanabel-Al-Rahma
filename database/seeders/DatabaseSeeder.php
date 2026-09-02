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
            AdminUserSeeder::class,
        ]);
    }
}
