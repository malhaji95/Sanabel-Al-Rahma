<?php

use App\Models\ContentCard;
use Database\Seeders\HomePageSeeder;

/*
 | T-260 — the door artwork reaches the site they already have.
 |
 | T-252 and T-256 put the association's eight door pictures in the seeder,
 | and every test passed, because a test seeds an empty database: there every
 | tile is a new row and `firstOrCreate` writes the picture with it. On their
 | own installation the eight tiles existed already, so firstOrCreate found
 | each one and wrote nothing — five door tiles with no picture at all and
 | three still carrying the stand-ins from August.
 |
 | What is held below is the whole of the rule: a picture a deploy put there
 | may be replaced by a later deploy, and a file the association uploaded
 | from the panel may not.
 */
beforeEach(function () {
    seedCore();
    (new HomePageSeeder)->run();
});

function tile(string $title): ContentCard
{
    return ContentCard::where('block', 'tile')->where('title_ar', $title)->firstOrFail();
}

it('fills a tile that has no picture at all', function () {
    $tile = tile('تفريج كربة');
    $tile->update(['image' => null]);

    (new HomePageSeeder)->run();

    expect($tile->refresh()->image)->toBe('images/doors/relief.webp');
});

it('replaces a stand-in an earlier release had put there', function () {
    // The exact state their database was in: the tile carries the stand-in
    // we shipped before they sent their own artwork.
    $tile = tile('ترميم منزل');
    $tile->update(['image' => 'images/home/home-repair.webp']);

    (new HomePageSeeder)->run();

    expect($tile->refresh()->image)->toBe('images/doors/home-repair.webp');
});

it('never writes over a picture the association uploaded', function () {
    /*
     | The panel's upload writes to `home/<file>` on the public disk, with no
     | `images/` in front of it, which is how a release tells its own artwork
     | from theirs. A tile they have dressed themselves is left alone for
     | good, deploy after deploy.
     */
    $tile = tile('كفالة يتيم');
    $tile->update(['image' => 'home/01JD-their-own-photograph.webp']);

    (new HomePageSeeder)->run();

    expect($tile->refresh()->image)->toBe('home/01JD-their-own-photograph.webp');
});

it('leaves every door tile with a picture, which is what the launch check asks', function () {
    // Each tile emptied, as the five of theirs were.
    ContentCard::where('block', 'tile')->update(['image' => null]);

    (new HomePageSeeder)->run();

    $tiles = ContentCard::where('block', 'tile')->where('is_published', true)->get();

    expect($tiles)->toHaveCount(8)
        ->and($tiles->whereNull('image'))->toBeEmpty()
        ->and($tiles->pluck('image')->every(fn (string $i) => str_starts_with($i, 'images/doors/')))->toBeTrue();
});

it('touches nothing else on a tile the association has edited', function () {
    $tile = tile('صدقة جارية');
    $tile->update(['title_ar' => 'صدقة جارية', 'link' => '/cases?supportType=one_time&theirs=1', 'image' => null]);

    (new HomePageSeeder)->run();

    expect($tile->refresh()->image)->toBe('images/doors/sadaqah.webp')
        ->and($tile->link)->toBe('/cases?supportType=one_time&theirs=1');
});
