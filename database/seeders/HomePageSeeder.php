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
        $this->aboutTiles();
        $this->homeFaq();
    }

    /*
     | T-327 — «تعرّف علينا»: the six doors the approved page puts between the
     | paths and the questions. Each is a page the association already writes;
     | what is new is the one line under each heading, because a reader
     | choosing between six doors needs to know what is behind them before
     | opening one.
     |
     | Rows, so the line, the order and whether a door shows at all are the
     | association's, and the glyph is the one the design drew.
     */
    private function aboutTiles(): void
    {
        foreach ([
            ['الهيئة الشرعية', 'ضوابط صرف الزكاة والصدقات', '/sharia-board', 'scales'],
            ['من نحن', 'رسالتنا ومبادئنا في خدمة المجتمع', '/about', 'info'],
            ['تواصل معنا', 'قنوات التواصل الرسمية', '/contact', 'phone'],
            ['الرقابة والتدقيق', 'فصل الصلاحيات ومراجعة العمليات', '/internal-oversight', 'shield-check'],
            ['الشفافية المالية', 'إيضاح حركة التبرعات والمساعدات', '/financial-transparency', 'card'],
            ['الأسئلة الشائعة', 'إجابات عن رحلة التبرع', '/faq', 'info'],
        ] as $index => [$title, $body, $link, $icon]) {
            $this->card('about_tile', $title, [
                'body_ar' => $body,
                'link' => $link,
                'icon' => $icon,
            ], $index + 1);
        }
    }

    /*
     | T-327 — the four questions the approved page puts on the front, chosen
     | because they are the four a first-time donor asks before giving. The
     | rest live on «الأسئلة الشائعة», and the band ends with the link to it.
     */
    private function homeFaq(): void
    {
        /*
         | Two of the four answers on their file describe the file itself —
         | «لا، هذه نسخة لمراجعة التصميم» — because it was a mock with no
         | money behind it. Repeating that here would be a lie on a running
         | site, so those two say what this system actually does.
         */
        foreach ([
            [
                'كيف تُحدَّد الأسر الأكثر احتياجاً؟',
                'تُدرس بيانات الأسرة وتُراجع وفق عوامل الاحتياج المعتمدة, ثم تُرتَّب الأولويات دون محاباة.',
            ],
            [
                'كيف أعرف أين يذهب تبرعي؟',
                'يُوثَّق استلام التبرع وتخصيصه وصرفه, ويظهر في السجلات والتقارير وفق الصلاحيات وحماية الخصوصية.',
            ],
            [
                'هل تُعرض صور أو أسماء الأسر؟',
                'تُستخدم صور تصنيفية ثابتة, ولا تُنشر البيانات الشخصية الحسّاسة للأسر.',
            ],
            [
                'كيف يصل المال إلى الأسرة؟',
                'كلّ تحويل يمرّ بصندوق الجمعية ثم بأمر صرفٍ معتمد, ولا يدفع المتبرع إلى الأسرة مباشرةً.',
            ],
        ] as $index => [$question, $answer]) {
            $this->card('faq_home', $question, [
                'body_ar' => $answer,
                'link' => '/faq',
            ], $index + 1);
        }
    }

    /*
     | T-327 — «اشتغل على نفس الشكل تماما», 26 November.
     |
     | The approved page carries five slides, and they are the five wide
     | messages of T-310 — technology, the money trail, fairness, the sharia
     | rules and oversight — in that order and nothing beside them.
     |
     | Six older slides used to open the page in front of them. Two of those
     | were never given artwork at all, and one, «كفالة يتيم», was drawn over
     | the direct-payment banner, so the home page said one thing in words and
     | another in the picture under them. They came from a time before the
     | association sent its own art; the art has arrived, so they go.
     |
     | A fresh install no longer ships them, and an install that already has
     | them is left with the rows intact and unpublished — a seeder does not
     | destroy what the association may have edited, and an unpublished banner
     | is one switch away from coming back.
     */
    private function heroSlide(): void
    {
        $this->wideMessages();

        Banner::whereIn('title_ar', [
            'بيدك.. تصنع الأمل',
            'كفالة يتيم',
            'زكاة مستحقة',
            'علاج مريض',
            'حملاتنا المفتوحة',
            'مسار الكفاية',
        ])->update(['is_published' => false]);
    }

    /*
     | T-310 — the five wide messages, re-exported at 1920×720 on 23 November.
     |
     | T-252 published the files in November and wired them to nothing,
     | leaving which of them belongs in the carousel to the association. They
     | did not choose, and on 23 November asked for them on the front page, so
     | they go in last — after the five slides that were already there — and
     | every one of them is a row the association reorders, renames or hides.
     |
     | Each destination is the page that answers its own sentence, because a
     | slide whose button goes somewhere other than its words is the
     | commonest lie on a charity home page:
     |
     |   · «اكتشف المنصة»            -> من نحن, where the platform is described
     |   · «السياسة المالية»          -> الشفافية المالية
     |   · «معايير الاستحقاق»         -> كيف نعمل, which carries the method
     |   · «الضوابط الشرعية»          -> الأسئلة الشائعة, which answers what the
     |                                  sharia board does and how zakat is
     |                                  channelled
     |   · «منظومة الرقابة»           -> الرقابة الداخلية
     |
     | `text_in_image` is what keeps the carousel from writing over them.
     */
    private function wideMessages(): void
    {
        foreach ([
            ['التقنية في خدمة العطاء', 'images/banners/technology.webp', '/about'],
            ['لا تداول نقدي... بل مسار مالي موثّق', 'images/banners/money-trail.webp', '/financial-transparency'],
            ['العدالة في الاستحقاق', 'images/banners/fairness.webp', '/how-we-work'],
            ['ضوابط شرعية واضحة', 'images/banners/sharia.webp', '/faq'],
            ['رقابة مستقلة تحمي الأمانة', 'images/banners/oversight.webp', '/internal-oversight'],
        ] as $index => [$title, $image, $link]) {
            Banner::firstOrCreate(
                ['title_ar' => $title],
                [
                    'body_ar' => null,
                    'image' => $image,
                    'link' => $link,
                    'text_in_image' => true,
                    'is_published' => true,
                    'sort_order' => 10 + $index,
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
        /*
         | T-327 — «اشتغل على نفس الشكل تماما», 26 November, over the approved
         | front page. Its bar carries six headings and not eight: the two the
         | association had on it that the design drops («الرئيسية», because the
         | logo already goes home, and «الأخبار», because the news has a band of
         | its own at the foot of the page) come out, and «المسار الصحي» goes in.
         |
         | Their file is one page with anchors, so «المسار الصحي» points at the
         | band. The other five have pages of their own and keep them: an anchor
         | where a page exists would strand a reader who is not on the home page,
         | and the bar is drawn on every page of the site.
         |
         | Rows, not code: the association reorders, renames or hides any of
         | them from the panel, and what is written here is only what a fresh
         | install ships with.
         */
        foreach ([
            ['المسار الصحي', '/#health'],
            ['مسار الكفاية', '/sufficiency-path'],
            ['الحالات', '/cases'],
            ['فزعة', '/campaigns'],
            ['الزكاة المستحقة', '/cases?zakat=1'],
            ['الهدايا', '/gift'],
        ] as $index => [$title, $link]) {
            $this->card('nav_link', $title, ['link' => $link], $index + 1);
        }

        /*
         | The bar is rows, and rows already on a running system keep whatever
         | the association last set on them — so the six are given their place
         | and their switch here, and every other heading that used to be on
         | the bar is hidden rather than deleted. A hidden row is one switch
         | away from coming back; a deleted one is a support call.
         */
        $wanted = ['المسار الصحي', 'مسار الكفاية', 'الحالات', 'فزعة', 'الزكاة المستحقة', 'الهدايا'];

        foreach ($wanted as $index => $title) {
            ContentCard::where('block', 'nav_link')->where('title_ar', $title)
                ->update(['sort_order' => $index + 1, 'is_published' => true]);
        }

        ContentCard::where('block', 'nav_link')->whereNotIn('title_ar', $wanted)
            ->update(['is_published' => false]);
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
        /*
         | T-327 — their approved page puts «إهداء التبرع» first and «الدفع
         | المباشر» second, which on a right-to-left page is gift on the right.
         | The order here is the order they drew.
         */
        $this->card('feature', 'تبرع سريع', [
            'body_ar' => 'ساهم الآن ونحن نوصلها لأكثر الحالات حاجة',
            'image' => 'images/home/direct-donation.webp',
            'icon' => 'hand-heart',
            'link' => '/donate',
        ], 2);

        $this->card('feature', 'هدية', [
            'body_ar' => 'أهدِ ما تحب .. يصنع سعادة لغيرك',
            'image' => 'images/home/gift.webp',
            'icon' => 'gift',
            // T-242 — it had no link, because there was no gift anywhere in
            // the system. There is one now.
            'link' => '/gift',
        ], 1);
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
         | T-256 — the eighth was left unwired because their artwork said
         | «كفالة حلقة قرآن» and the nearest tile said «كفالة طالب علم»,
         | and the two did not obviously mean the same thing. The
         | association answered on 5 November: «باب كفالة العلم هو نفسه باب
         | كفالة حلقة قرآن». One door, so one picture.
         |
         | The tile keeps the name their own classification uses — «طالب
         | علم» is one of the three sponsorship doors they named in T-205 —
         | and the name is a row they edit if they would rather it read
         | «كفالة حلقة قرآن».
         */
        foreach ([
            ['ترميم منزل', 'images/doors/home-repair.webp', 'home', $oneTime],
            ['علاج مريض', 'images/doors/medical.webp', 'medical', $oneTime],
            ['تفريج كربة', 'images/doors/relief.webp', 'hand-heart', $oneTime],
            ['كفالة يتيم', 'images/doors/orphan.webp', 'child', $monthly],
            ['كفالة طالب علم', 'images/doors/quran-circle.webp', 'book', $monthly],
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

            $this->fillTileArtwork($title, $image);
        }
    }

    /*
     | T-260 — the artwork reaches a tile that already exists.
     |
     | `card()` is firstOrCreate, which is the thing that keeps a title or a
     | destination the association edited from being written over on the next
     | deploy. It also meant the eight door pictures of T-252 and T-256
     | landed on a fresh database only: on the site they already have, five
     | door tiles stayed with no picture at all and three kept the stand-ins
     | we had put there in August. So the artwork they sent on 4 November was
     | in the repository and nowhere a donor could see it, and the tests did
     | not say so because a test seeds an empty database, where every row is
     | new and firstOrCreate writes everything.
     |
     | A picture a release put there is a release's to replace; a file they
     | uploaded is not. The two are told apart by where they live: the
     | panel's upload writes `home/<file>` on the public disk, and anything
     | under `images/` was put there by a deploy.
     */
    private function fillTileArtwork(string $title, string $image): void
    {
        $card = ContentCard::where('block', 'tile')->where('title_ar', $title)->first();

        if ($card === null || $card->image === $image) {
            return;
        }

        if ($card->image !== null && ! str_starts_with($card->image, 'images/')) {
            return;
        }

        $card->update(['image' => $image]);
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
