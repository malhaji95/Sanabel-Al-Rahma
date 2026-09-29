<?php

use App\Models\Banner;
use App\Models\Campaign;

/*
 | The banner carried an image column and a free-text link that nothing used.
 | An editor can now upload artwork and point a banner at a campaign by name.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
});

it('sends a banner to the campaign it names, not to the typed link', function () {
    $campaign = Campaign::factory()->create(['is_published' => true, 'surplus_policy_text_ar' => 'يُحوَّل الفائض إلى الصندوق العام.']);
    $banner = Banner::factory()->create([
        'campaign_id' => $campaign->id,
        'link' => 'https://example.test/stale',
    ]);

    expect($banner->destination())->toContain('#campaign-'.$campaign->id)
        ->and($banner->destination())->not->toContain('stale');
});

it('falls back to the typed link when no campaign is named', function () {
    $banner = Banner::factory()->create(['campaign_id' => null, 'link' => 'https://example.test/page']);

    expect($banner->destination())->toBe('https://example.test/page');
});

it('stops pointing at a campaign that was unpublished', function () {
    $campaign = Campaign::factory()->create(['is_published' => false]);
    $banner = Banner::factory()->create(['campaign_id' => $campaign->id, 'link' => null]);

    expect($banner->destination())->toBeNull();
});

it('serves banner artwork from the public disk, never the media disk', function () {
    $banner = Banner::factory()->create(['image' => 'banners/ramadan.jpg']);

    expect($banner->imageUrl())->toContain('banners/ramadan.jpg')
        // The media disk is private and signed; a public banner must not use it.
        ->and($banner->imageUrl())->not->toContain('signature');
});

it('shows the banner and its link on the home page', function () {
    $campaign = Campaign::factory()->create([
        'is_published' => true,
        'title_ar' => 'حملة الشتاء',
        'surplus_policy_text_ar' => 'يُحوَّل الفائض إلى الصندوق العام.',
    ]);
    Banner::factory()->create([
        'title_ar' => 'بانر تجريبي',
        'campaign_id' => $campaign->id,
        'is_published' => true,
    ]);

    $this->get('/')
        ->assertOk()
        ->assertSee('بانر تجريبي')
        ->assertSee('#campaign-'.$campaign->id);
});
