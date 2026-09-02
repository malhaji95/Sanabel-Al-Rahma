<?php

namespace Database\Seeders;

use App\Models\Region;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $region = Region::where('type', 'governorate')->first();

        User::firstOrCreate(
            ['email' => 'admin@sanabel.local'],
            [
                'name' => 'مدير النظام',
                'password' => Hash::make(env('SEED_ADMIN_PASSWORD', 'password')),
                'role_id' => Role::where('key', 'admin')->value('id'),
                'region_id' => $region?->id,
                'is_active' => true,
            ],
        );
    }
}
