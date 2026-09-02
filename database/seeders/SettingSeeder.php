<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

/** Every operational default from docs/07-decisions.md, as data. */
class SettingSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('sanabel.setting_defaults') as $key => $value) {
            Setting::firstOrCreate(['key' => $key], ['value_json' => $value]);
        }

        Setting::firstOrCreate(['key' => 'membership_categories'], ['value_json' => [
            'basic' => ['name_ar' => 'عضوية أساسية', 'amount' => 0, 'cycle' => 'monthly'],
        ]]);

        Setting::firstOrCreate(['key' => 'feature_flags'], ['value_json' => [
            'zakat_fund' => false,
            'self_registration' => false,
        ]]);
    }
}
