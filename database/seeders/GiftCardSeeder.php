<?php

namespace Database\Seeders;

use App\Models\GiftCard;
use Illuminate\Database\Seeder;

/**
 * T-309 — the association's own gift artwork, sent on 23 November.
 *
 * Two packs: «العامة», thirteen phrases anybody may send, and «الخاصة»,
 * sixteen tied to a named relation — a parent, a sibling, a friend, a
 * spouse. Each phrase in two colourways, «زاهي» and «فاخر», which is T-264
 * working as the association asked on 8 November: the giver switches
 * between the two before sending, and the one chosen is kept on the gift.
 *
 * Keyed on the picture, not the name: the pack carries one phrase twice —
 * «بارك الله لكما وجمع بينكما في خير» is in both sections — and keying on
 * the name would silently drop the second design. The association renames
 * either of them from the panel; the rows are theirs.
 *
 * The pictures ship in `public/images/gift-cards` with the banners and the
 * door artwork, so a deployment carries them and nobody uploads anything.
 */
class GiftCardSeeder extends Seeder
{
    private const VIVID = 'زاهي';

    private const LUXE = 'فاخر';

    /** @var array<int,array{0:string,1:string}> */
    private const GENERAL = [
        ['01', 'لأنك تستحق أجمل العطاء'],
        ['02', 'إلى من لا يوفيهما العطاء حقهما'],
        ['03', 'إلى رفيق العمر وطمأنينة القلب'],
        ['04', 'للقلوب التي تسكن قلوبنا'],
        ['05', 'رحلوا... وبقي الدعاء'],
        ['06', 'أسأل الله أن يلبسك ثوب العافية'],
        ['07', 'لأن بعض الجميل لا تفيه الكلمات'],
        ['08', 'بارك الله لكما وجمع بينكما في خير'],
        ['09', 'أشرقت الدنيا بقدوم صغيركم'],
        ['10', 'رمضان... حين يزهر العطاء'],
        ['11', 'عيدكم فرح... وعطاؤكم أثر'],
        ['12', 'في يوم الجمعة... دعوةٌ وعطاء'],
        ['13', 'صدقة عن روح عزيز'],
    ];

    /** @var array<int,array{0:string,1:string}> */
    private const SPECIAL = [
        ['01', 'بداية جميلة ووعد بالخير'],
        ['02', 'بارك الله لكما وجمع بينكما في خير'],
        ['03', 'ومعك تزداد الأيام جمالاً'],
        ['04', 'خطوة جديدة تستحق الفرح'],
        ['05', 'لأن الأثر الطيب لا يُنسى'],
        ['06', 'تقبّل الله طاعتك'],
        ['07', 'الحمد لله على سلامة العودة'],
        ['08', 'جعله الله بيتاً عامراً بالخير'],
        ['09', 'للصداقة التي لا تغيّرها الأيام'],
        ['10', 'لأن رابطة الأخوّة لا تشبه سواها'],
        ['11', 'أبي لك في القلب مقام لا يبلغه أحد'],
        ['12', 'أمي دعاؤك أجمل ما يرافقني'],
        ['13', 'صدقة عن روح أبي'],
        ['14', 'صدقة عن روح أمي'],
        ['15', 'صدقة عن روح أخي أو أختي'],
        ['16', 'صدقة عن روح صديقي'],
    ];

    public function run(): void
    {
        $order = 0;

        foreach ([['general', self::GENERAL], ['special', self::SPECIAL]] as [$section, $rows]) {
            foreach ($rows as [$number, $nameAr]) {
                $order++;

                GiftCard::firstOrCreate(
                    ['image_path' => "images/gift-cards/{$section}-{$number}-a.webp"],
                    [
                        'name_ar' => $nameAr,
                        'image_path_alt' => "images/gift-cards/{$section}-{$number}-b.webp",
                        'variant_ar' => self::VIVID,
                        'variant_alt_ar' => self::LUXE,
                        'is_published' => true,
                        'sort_order' => $order,
                    ],
                );
            }
        }
    }
}
