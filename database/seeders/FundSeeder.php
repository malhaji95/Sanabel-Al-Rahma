<?php

namespace Database\Seeders;

use App\Models\Fund;
use Illuminate\Database\Seeder;

class FundSeeder extends Seeder
{
    public function run(): void
    {
        // Membership money can never be counted as family coverage.
        $funds = [
            [Fund::OPERATIONAL, 'الصندوق التشغيلي', true],
            [Fund::RESTRICTED, 'الصندوق المقيّد', true],
            [Fund::ZAKAT, 'صندوق الزكاة', true],
            [Fund::MEMBERSHIP, 'صندوق العضويات', false],
        ];

        foreach ($funds as [$key, $nameAr, $canFundFamilies]) {
            Fund::updateOrCreate(['key' => $key], [
                'name_ar' => $nameAr,
                'can_fund_families' => $canFundFamilies,
            ]);
        }
    }
}
