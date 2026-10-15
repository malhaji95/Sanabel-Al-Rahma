<?php

namespace Database\Seeders;

use App\Models\Banner;
use App\Models\ContentCard;
use App\Models\Page;
use Illuminate\Database\Seeder;

/**
 * T-89 — the home page the association approved, as rows.
 *
 * Everything here is editable from the panel the moment it is seeded: the
 * wording, the order, the pictures, the destinations. This only puts the first
 * version on the page so the association edits rather than composes.
 *
 * Two honest gaps, left visible rather than papered over:
 *
 *   - «هدية» is a card with no destination. Gift giving — donating in someone
 *     else's name — is not a thing this system does yet, and sending the
 *     button somewhere approximate would be worse than leaving it off. The
 *     card draws its button only once a link is set.
 *   - the pictures are the ones the design pack supplied. Seven of its
 *     thirteen never arrived (the archive was truncated), so those cards fall
 *     back to their icon, which is what the icon is for.
 */
class HomePageSeeder extends Seeder
{
    public function run(): void
    {
        $this->heroSlide();
        $this->navLinks();
        $this->features();
        $this->tiles();
        $this->advantages();
        $this->footerLinks();
    }

    /**
     * One slide, so the first band of the page is not an empty space on a
     * fresh install. Banners already carry a picture, a title, a line and a
     * destination; the association edits this one or adds its own beside it.
     */
    private function heroSlide(): void
    {
        Banner::firstOrCreate(
            ['title_ar' => 'بيدك.. تصنع الأمل'],
            [
                'body_ar' => 'معاً نحيي حياة.. ونرسم مستقبلاً أفضل',
                'image' => 'images/home/hero-child.webp',
                'link' => '/cases',
                'is_published' => true,
                'sort_order' => 1,
            ],
        );
    }

    private function card(string $block, string $title, array $attributes, int $order): void
    {
        ContentCard::firstOrCreate(
            ['block' => $block, 'title_ar' => $title],
            $attributes + ['sort_order' => $order, 'is_published' => true],
        );
    }

    /**
     * The bar at the top.
     *
     * Only the destinations that exist. The design also names «هدية»,
     * «كفالات» and «زكاة مستحقة»; each of those is a feature or a page that
     * has still to be built or written, and a bar that promises one and
     * answers 404 is worse than a bar that does not. They are added here, from
     * the panel, the day they exist.
     */
    private function navLinks(): void
    {
        foreach ([
            ['الرئيسية', '/'],
            ['الحالات', '/cases'],
            // T-98 — the association asked for it beside the campaigns on 15
            // October. It is the published list narrowed to the files a zakat
            // payment may be spent on, not a page of its own: one list, one
            // ranking, one set of masked cards.
            ['زكاة مستحقة', '/cases?zakat=1'],
            ['الحملات', '/campaigns'],
            ['الأخبار', '/news'],
        ] as $index => [$title, $link]) {
            $this->card('nav_link', $title, ['link' => $link], $index + 1);
        }
    }

    /** The two offers under the hero. */
    private function features(): void
    {
        $this->card('feature', 'الدفع المباشر', [
            'body_ar' => 'ساهم الآن مباشرة في دعم الحالات المحتاجة',
            'image' => 'images/home/direct-donation.webp',
            'icon' => 'hand-heart',
            'link' => '/cases',
        ], 1);

        $this->card('feature', 'هدية', [
            'body_ar' => 'أهدِ ما تحب .. يصنع سعادة لغيرك',
            'image' => 'images/home/gift.webp',
            'icon' => 'gift',
            // No link: see the note at the top of this file.
            'link' => null,
        ], 2);
    }

    /** The ways to give. */
    private function tiles(): void
    {
        // A one-off need and a standing one are two different lists, and the
        // browse screen already separates them — so a tile lands on the right
        // one rather than on an undifferentiated page.
        $oneTime = '/cases?supportType=one_time';
        $monthly = '/cases?supportType=monthly';

        foreach ([
            ['ترميم منزل', 'images/home/home-repair.webp', 'home', $oneTime],
            ['علاج مريض', 'images/home/medical.webp', 'medical', $oneTime],
            ['تفريج كربة', null, 'hand-heart', $oneTime],
            ['كفالة يتيم', 'images/home/hero-child.webp', 'child', $monthly],
            ['كفالة طالب علم', null, 'book', $monthly],
            // T-98 — it used to land on every one-time need, zakat-eligible or
            // not, which is the one tile on this page a donor could act on and
            // be wrong about.
            ['زكاة واجبة', null, 'sprout', $oneTime.'&zakat=1'],
            ['صدقة جارية', null, 'mosque', $oneTime],
            ['رعاية أرملة', null, 'person', $monthly],
        ] as $index => [$title, $image, $icon, $link]) {
            $this->card('tile', $title, [
                'image' => $image,
                'icon' => $icon,
                'link' => $link,
            ], $index + 1);
        }
    }

    /** «ما يميز سنابل الرحمة» — the association's own words. */
    private function advantages(): void
    {
        foreach ([
            ['لا نستلم نقداً من المتبرع للمستحق مباشرة', 'card'],
            ['الأشد حاجة شعارنا والمناطقية ليست معيارنا', 'family'],
            ['نحقق وندقق لننصف المحتاج ونبعد غير المحتاج', 'file-search'],
            ['كرامة المحتاج أولويتنا', 'heart'],
            ['نعمل بالعدل لا بالمساواة', 'shield-check'],
            ['هيئة شرعية معتمدة', 'scales'],
        ] as $index => [$title, $icon]) {
            $this->card('advantage', $title, ['icon' => $icon], $index + 1);
        }
    }

    /**
     * The footer the design asks for. Four of the seven have no page behind
     * them yet, so each is opened as a published page carrying one line that
     * says it is waiting for the association's own text — a visitor meets a
     * sentence rather than a 404, and the association sees exactly where to
     * type.
     */
    private function footerLinks(): void
    {
        $waiting = 'هذه الصفحة بانتظار نصّها من الجمعية.';

        // The two cards that close the page. «من نحن» already exists; the
        // board's own line is the one the association wrote on the design.
        Page::firstOrCreate(['slug' => 'sharia-board'], [
            'title_ar' => 'الهيئة الشرعية',
            'body_ar' => 'بإشراف هيئة شرعية معتمدة من علماء موثوقين، لضمان مطابقة أعمالنا لأحكام الشريعة الإسلامية.',
            'is_published' => true,
            'sort_order' => 1,
        ]);

        foreach ([
            ['كيف نعمل', 'how-we-work', true],
            ['من نحن', 'about', true],
            // The policy's own page is PrivacyPolicySeeder's, text and version
            // and all. A placeholder opened here would take its slug.
            ['سياسة الخصوصية', 'privacy', false],
            ['لجنة الرقابة والتفتيش الداخلي', 'internal-oversight', true],
            ['شروط الاستخدام', 'terms', true],
            ['الأسئلة الشائعة', 'faq', true],
            ['الشفافية المالية', 'financial-transparency', true],
            // T-98 — asked for on 15 October, and it was missing. The page
            // opens with the same «بانتظار نصّها» placeholder as its
            // neighbours: the telephone, the address and the hours are the
            // association's to write, and inventing them would be worse than
            // leaving the page waiting.
            ['تواصل معنا', 'contact', true],
        ] as $index => [$title, $slug, $mayOpen]) {
            // firstOrCreate: a page the association has already written keeps
            // every word of it.
            if ($mayOpen) {
                Page::firstOrCreate(
                    ['slug' => $slug],
                    ['title_ar' => $title, 'body_ar' => $waiting, 'is_published' => true, 'sort_order' => $index],
                );
            }

            $this->card('footer_link', $title, ['link' => '/'.$slug], $index + 1);
        }
    }
}
