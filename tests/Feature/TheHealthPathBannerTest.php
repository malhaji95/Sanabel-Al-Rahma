<?php

use App\Models\Provider;
use Database\Seeders\HomePageSeeder;
use Database\Seeders\PrivacyPolicySeeder;

/*
 | T-150 — «نعتمد البنر المرفق ليظهر في الصفحة الرئيسية … ساهم كمزود خدمة:
 | الانتقال إلى تسجيل مقدمي الخدمات الصحية. ساهم في العلاج: الانتقال إلى صفحة
 | التبرع للمسار الصحي. مع عدم إظهار نسب الخصومات أو تفاصيل الاتفاقيات
 | للجمهور», 29 October.
 |
 | The artwork is shown as it was supplied and its two drawn buttons are real
 | links. What the association contracted with each provider — the discount,
 | the quota, the value — stays in the panel and is not published with it.
 */

beforeEach(function () {
    seedCore();
    (new PrivacyPolicySeeder)->run();
    (new HomePageSeeder)->run();
});

it('shows the approved artwork above the association’s own cards', function () {
    $page = $this->get('/');

    $page->assertOk()
        ->assertSee('images/home/health-path.webp')
        ->assertSee(__('sanabel.health_path.banner_alt'));

    /*
     | Above «من نحن», which is where the association asked for it.
     |
     | T-327 — against the band and not against the words: «من نحن» is now a
     | door in the drawer too, and the drawer is in the header, so the bare
     | phrase first appears long before any band of the page.
     */
    $html = $page->getContent();

    expect(strpos($html, 'health-path.webp'))->toBeLessThan(strpos($html, 'id="about"'));
});

it('gives each drawn button somewhere real to go', function () {
    $page = $this->get('/');

    $page->assertSee(__('sanabel.health_path.give_treatment'))
        ->assertSee(__('sanabel.health_path.join_provider'))
        ->assertSee(route('donate.general'))
        ->assertSee(route('page', 'contact'));
});

it('publishes no discount and no agreement with it', function () {
    $provider = Provider::factory()->create([
        'name_ar' => 'مشفى تجريبي',
        'discount_type' => 'percent',
        'discount_value' => 35,
        'case_quota' => 12,
    ]);

    $this->get('/')
        ->assertDontSee($provider->name_ar)
        ->assertDontSee(__('sanabel.provider.discount_value'))
        ->assertDontSee('35%');
});
