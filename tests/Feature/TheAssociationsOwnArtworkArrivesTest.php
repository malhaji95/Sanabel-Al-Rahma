<?php

use App\Models\GiftCard;
use Database\Seeders\GiftCardSeeder;

/*
 | T-309 — the association's own artwork, sent on 23 November: five hero
 | banners re-exported at the approved size, and twenty-nine gift phrases in
 | two colourways each.
 |
 | The failure this guards against is the quiet one. A row may name a
 | picture that was never deployed, and nothing complains: the page renders,
 | the card is listed, and the giver is shown a broken frame. So the rows
 | and the files are checked against each other, not separately.
 */

/** The approved hero size in the association's own asset guide. */
const HERO_WIDTH = 1920;

const HERO_HEIGHT = 720;

/** The size the gift pack was drawn at. */
const CARD_WIDTH = 1200;

const CARD_HEIGHT = 675;

it('carries the five hero banners at the size their guide approves', function () {
    $banners = [
        'technology.webp',   // التقنية في خدمة العطاء
        'money-trail.webp',  // لا تداول نقدي... بل مسار مالي موثّق
        'fairness.webp',     // لا واسطة... لا محاباة... لا مناطقية
        'sharia.webp',       // ضوابط شرعية واضحة
        'oversight.webp',    // رقابة مستقلة تحمي الأمانة
    ];

    foreach ($banners as $file) {
        $path = public_path("images/banners/{$file}");

        expect(file_exists($path))->toBeTrue("missing banner: {$file}");

        [$width, $height] = getimagesize($path);

        expect($width)->toBe(HERO_WIDTH, "wrong width: {$file}")
            ->and($height)->toBe(HERO_HEIGHT, "wrong height: {$file}");
    }
});

it('gives every seeded phrase two pictures that are really there', function () {
    (new GiftCardSeeder)->run();

    $cards = GiftCard::where('image_path', 'like', 'images/gift-cards/%')->get();

    expect($cards)->toHaveCount(29);

    foreach ($cards as $card) {
        expect($card->hasAlternate())->toBeTrue("no second colour: {$card->name_ar}");

        foreach ([$card->image_path, $card->image_path_alt] as $path) {
            $file = public_path($path);

            expect(file_exists($file))->toBeTrue("missing artwork: {$path}");

            [$width, $height] = getimagesize($file);

            expect($width)->toBe(CARD_WIDTH, "wrong width: {$path}")
                ->and($height)->toBe(CARD_HEIGHT, "wrong height: {$path}");
        }

        // The two colourways are two pictures, not the same one twice.
        expect($card->imageUrlFor('b'))->not->toBe($card->imageUrlFor('a'));

        // And each colour is named, because the giver chooses between them.
        expect($card->variantLabel('a'))->not->toBeEmpty()
            ->and($card->variantLabel('b'))->not->toBeEmpty();
    }
});

it('reads artwork that ships with the application from where it was deployed', function () {
    /*
     | Artwork uploaded from the panel lives on the public disk; artwork that
     | ships in `public/` does not, and resolving it there would hand the
     | giver a URL for a file nobody ever put on that disk.
     */
    (new GiftCardSeeder)->run();

    $card = GiftCard::where('image_path', 'like', 'images/gift-cards/%')->firstOrFail();

    expect($card->imageUrl())->toContain('/images/gift-cards/')
        ->and($card->imageUrl())->not->toContain('/storage/');
});

it('seeds the same rows however often it runs', function () {
    (new GiftCardSeeder)->run();
    (new GiftCardSeeder)->run();

    expect(GiftCard::where('image_path', 'like', 'images/gift-cards/%')->count())->toBe(29);
});
