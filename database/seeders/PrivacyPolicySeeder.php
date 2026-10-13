<?php

namespace Database\Seeders;

use App\Models\Page;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

/*
 | T-81 — the association's privacy policy, adopted 13 October 2026.
 |
 | The consent columns have existed since T-69 and the file has refused to leave
 | draft without them since T-74, but there was no policy to show: a family was
 | agreeing to a document nobody could produce. This publishes it.
 |
 | The text is data, in database/data/policies/privacy-<version>.md, and the
 | version in force is a setting. They move together on purpose: a new version
 | is a new file and a changed setting, so «which text did this family agree
 | to» always has an answer. A typo fixed in the CMS does not silently become a
 | new version, and a new version cannot appear without its text.
 |
 | The association adopted this one as it stands and said their legal adviser
 | may revise it later; the date they left as «[يُحدد لاحقًا]» is the date they
 | adopted it.
 */
class PrivacyPolicySeeder extends Seeder
{
    public const SLUG = 'privacy';

    public function run(): void
    {
        $version = (string) config('sanabel.privacy_policy.version');
        $path = database_path('data/policies/privacy-'.$version.'.md');

        if (! File::exists($path)) {
            $this->command?->warn("No text for privacy policy version {$version}; nothing published.");

            return;
        }

        Page::updateOrCreate(
            ['slug' => self::SLUG],
            [
                'title_ar' => __('sanabel.privacy.page_title'),
                'body_ar' => File::get($path),
                'is_published' => true,
                'sort_order' => 90,
            ],
        );

        $this->command?->info("Privacy policy {$version} published at /".self::SLUG);
    }
}
