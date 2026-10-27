<?php

use App\Models\Campaign;
use App\Models\Donor;

/*
 | T-168 — «تبرعات الملفات بالالوان ونسبة الانجاز تعامل نفس معاملة الحملات,
 | وحتى زر التبرع الان نفس موضوع», 31 October.
 |
 | T-123 answered this for the campaigns on 26 October: the word «تبرع»
 | belongs to every visitor, and an account is what a pledge needs — not what
 | the invitation needs. A family's card was still reading «سجل الدخول
 | للتبرع», a door with its own condition written on it. The two cards say the
 | same thing now.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
});

it('invites a visitor to give, on a family’s card as on a campaign’s', function () {
    $case = publishedCase($this->region);

    Campaign::factory()->create([
        'beneficiary_id' => $case->id,
        'goal_amount' => 100_000,
        'is_published' => true,
        'status' => 'active',
        'surplus_policy_text_ar' => 'يُحوَّل الفائض إلى الحالة التالية.',
    ]);

    $cases = $this->get(route('cases.browse'))->assertOk();
    $campaigns = $this->get(route('campaigns.public'))->assertOk();

    foreach ([$cases, $campaigns] as $page) {
        $page->assertSee(__('sanabel.public.donate_now'))
            ->assertSee(__('sanabel.public.login'));
    }

    // And the old wording, which put the condition in place of the welcome,
    // is on neither of them.
    $cases->assertDontSee(__('sanabel.public.login_to_donate'));
});

it('says underneath what the account is for', function () {
    publishedCase($this->region);

    $this->get(route('cases.browse'))
        ->assertOk()
        ->assertSee(__('sanabel.public.earmark_needs_account'));

    expect(__('sanabel.public.earmark_needs_account'))->toContain('باسمك');
});

it('still gives a signed-in donor the box rather than the invitation', function () {
    $case = publishedCase($this->region);
    $donor = userWithRole('donor');
    Donor::factory()->create(['user_id' => $donor->id]);

    $this->actingAs($donor->refresh())
        ->get(route('cases.browse'))
        ->assertOk()
        ->assertSee(__('sanabel.public.add'))
        ->assertDontSee(__('sanabel.public.earmark_needs_account'));
});
