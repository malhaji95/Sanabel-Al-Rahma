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
 * One honest gap, left visible rather than papered over. The other closed on
 * 3 November: «هدية» was a card with no destination, because giving in
 * somebody else's name was not a thing this system did, and sending the
 * button somewhere approximate would have been worse than leaving it off.
 * T-241 built the gift and T-242 its page, so the card has its link.
 *
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
        $this->partners();
    }

    /**
     * One slide, so the first band of the page is not an empty space on a
     * fresh install. Banners already carry a picture, a title, a line and a
     * destination; the association edits this one or adds its own beside it.
     */
    /*
     | T-99 — five slides, because the association asked for five on 15 October
     | and because a carousel with one slide is a picture.
     |
     | Each one leads where its own words promise: the one that says «زكاة» to
     | the zakat-eligible list, the one that says «كفالة» to the monthly list.
     | A slide whose button goes somewhere other than its sentence is the
     | commonest lie on a charity home page, and the easiest to avoid.
     |
     | Artwork: the design pack supplied five images, and the three slides it
     | did not cover open on their colour band rather than on somebody else's
     | photograph. The association replaces any of it from the panel without a
     | release — image, words, destination, order and whether it shows at all.
     */
    private function heroSlide(): void
    {
        foreach ([
            ['بيدك.. تصنع الأمل', 'معاً نحيي حياة.. ونرسم مستقبلاً أفضل', 'images/home/hero-child.webp', '/cases'],
            ['كفالة يتيم', 'كفالة شهرية تُغطّي احتياج أسرة، وتُجدَّد شهراً بشهر', 'images/home/direct-donation.webp', '/cases?supportType=monthly'],
            ['زكاة مستحقة', 'ملفّات راجعتها الجمعية وأقرّت أن الزكاة تُصرف عليها', null, '/cases?zakat=1'],
            ['علاج مريض', 'تكلفة علاجٍ محسوبة، وإثبات صرفٍ مرفوع', 'images/home/medical.webp', '/cases?supportType=one_time'],
            ['حملاتنا المفتوحة', 'هدفٌ معلن، وما جُمع منه، وسياسة الفائض مكتوبة', null, '/campaigns'],
            /*
             | T-253 — their own banner for their own page, sent together on
             | 4 November. The five other wide messages in the same pack are
             | deliberately not here: which of them belongs in the carousel,
             | and in what order, is a row on the association's own screen.
             */
            ['مسار الكفاية', 'من الحاجة إلى القدرة، ثم الاستقرار', 'images/banners/sufficiency-path.webp', '/sufficiency-path'],
        ] as $index => [$title, $body, $image, $link]) {
            Banner::firstOrCreate(
                ['title_ar' => $title],
                [
                    'body_ar' => $body,
                    'image' => $image,
                    'link' => $link,
                    'is_published' => true,
                    'sort_order' => $index + 1,
                ],
            );
        }
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
     * Only the destinations that exist. The design also named «هدية»,
     * «كفالات» and «زكاة مستحقة»; each of those was a feature or a page that
     * had still to be built or written, and a bar that promises one and
     * answers 404 is worse than a bar that does not. They are added here, from
     * the panel, the day they exist — «كفالات» on 3 November (T-232) and
     * «هدية» the same day the gift itself arrived (T-242).
     */
    /*
     | T-99 — «شركاؤنا». No rows are seeded: a partner is a real organisation
     | with a real logo and a real agreement, and inventing three for a
     | demonstration would put names on the association's page that never
     | agreed to be there. The band appears the moment they add the first one,
     | from the same screen as everything else, and stays invisible until then.
     */
    private function partners(): void
    {
        // Intentionally empty. The block exists; its rows are theirs.
    }

    private function navLinks(): void
    {
        /*
         | T-99 — the bar the association named on 15 October: هدية، كفالات،
         | حملات، زكاة مستحقة. All four are here now, in their order, with
         | الرئيسية and الحالات and الأخبار around them.
         |
         | «هدية» was the last one missing, and for the reason this file has
         | carried from the beginning: there was no gift anywhere in the
         | system — no donation on behalf of someone, no card, no recipient —
         | and a heading leading to a page that cannot do what it says is
         | worse than its absence. T-241 and T-242 built it.
         */
        foreach ([
            ['الرئيسية', '/'],
            ['الحالات', '/cases'],
            /*
             | T-232 — «نعتمد إضافة الكفالات إلى التنقل العلوي», 3 November.
             |
             | The row was here already, and it pointed at the reading we
             | held before they decided otherwise: «monthly support is what
             | a sponsorship is», so it opened the whole monthly list. Since
             | T-228 a sponsorship is its own page behind the association's
             | own doors, and that is where the word belongs.
             |
             | The bar is content (T-89), so on a system already running
             | this row is edited from the panel; what changes here is only
             | what a fresh install ships with.
             */
            ['الكفالات', '/sponsorships'],
            /*
             | T-242 — «هدية» was named in the bar the association drew on
             | 15 October and left out of it ever since, because the note at
             | the top of this file was true: there was no gift in the
             | system, and a heading leading to a page that cannot do what it
             | says is worse than its absence. The row goes in the day the
             | feature does, and this is that day.
             */
            ['هدية', '/gift'],
            /*
             | T-253 — «مسار الكفاية» joins the bar the day its page does.
             | The association sent its text and its banner on 4 November,
             | and the same rule holds as for «هدية»: a heading goes in when
             | the thing behind it exists, not before.
             */
            ['مسار الكفاية', '/sufficiency-path'],
            // The visitor's word is «فزعة» (T-232); the address does not
            // move, because their decision was the name and nothing else.
            ['فزعة', '/campaigns'],
            // T-98 — the files the association has judged zakat may be spent
            // on. One list, one ranking, one set of masked cards.
            ['زكاة مستحقة', '/cases?zakat=1'],
            ['الأخبار', '/news'],
        ] as $index => [$title, $link]) {
            $this->card('nav_link', $title, ['link' => $link], $index + 1);
        }
    }

    /** The two offers under the hero. */
    private function features(): void
    {
        /*
         | T-103 — it was «الدفع المباشر» landing on the list of families, and
         | the association asked for both halves of that to change on 15
         | October. The name read as though the money went from the donor to
         | the household outside the association, which is the one thing the
         | system does not do and their own decision of 29 September forbids.
         | And the card promised an act and delivered a list: it now lands on
         | the act, with no file to pick.
         */
        $this->card('feature', 'تبرع سريع', [
            'body_ar' => 'ساهم الآن ونحن نوصلها لأكثر الحالات حاجة',
            'image' => 'images/home/direct-donation.webp',
            'icon' => 'hand-heart',
            'link' => '/donate',
        ], 1);

        $this->card('feature', 'هدية', [
            'body_ar' => 'أهدِ ما تحب .. يصنع سعادة لغيرك',
            'image' => 'images/home/gift.webp',
            'icon' => 'gift',
            // T-242 — it had no link, because there was no gift anywhere in
            // the system. There is one now.
            'link' => '/gift',
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

        /*
         | T-252 — the association's own door artwork, sent on 4 November at
         | 800×400, replaces the stand-ins and fills the five tiles that had
         | no picture at all. Seven of their eight land on a tile by name.
         |
         | The eighth, «كفالة حلقة قرآن», has no tile: the nearest one says
         | «كفالة طالب علم», and a Qur'an circle and a student are not the
         | same thing. The file is in `public/images/doors/quran-circle.webp`
         | waiting for the association to say which it is — a tile is a row
         | they edit, so either answer is a line on a screen.
         */
        foreach ([
            ['ترميم منزل', 'images/doors/home-repair.webp', 'home', $oneTime],
            ['علاج مريض', 'images/doors/medical.webp', 'medical', $oneTime],
            ['تفريج كربة', 'images/doors/relief.webp', 'hand-heart', $oneTime],
            ['كفالة يتيم', 'images/doors/orphan.webp', 'child', $monthly],
            ['كفالة طالب علم', null, 'book', $monthly],
            // T-98 — it used to land on every one-time need, zakat-eligible or
            // not, which is the one tile on this page a donor could act on and
            // be wrong about.
            ['زكاة واجبة', 'images/doors/zakat.webp', 'sprout', $oneTime.'&zakat=1'],
            ['صدقة جارية', 'images/doors/sadaqah.webp', 'mosque', $oneTime],
            ['رعاية أرملة', 'images/doors/widow.webp', 'person', $monthly],
        ] as $index => [$title, $image, $icon, $link]) {
            $this->card('tile', $title, [
                'image' => $image,
                'icon' => $icon,
                'link' => $link,
            ], $index + 1);
        }
    }

    /** «ما يميز سنابل الوفاء» — the association's own words. */
    private function advantages(): void
    {
        foreach ([
            // T-103 — أعادت الجمعية صياغتها في 15 تشرين الأول: المعنى نفسه
            // بصيغة تقول ما نفعله بدل ما لا نفعله.
            ['تمر التبرعات عبر مسار مالي موثق ومراقب حتى تصل لمستحقيها', 'card'],
            ['الأشد حاجة شعارنا والمناطقية ليست معيارنا', 'family'],
            ['نحقق وندقق لننصف المحتاج ونبعد غير المحتاج', 'file-search'],
            ['كرامة المحتاج أولويتنا', 'heart'],
            ['نوجّه الدعم حسب الحاجة والاستحقاق', 'shield-check'],
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
