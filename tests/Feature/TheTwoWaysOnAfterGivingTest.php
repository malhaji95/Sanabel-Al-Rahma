<?php

use App\Livewire\PledgeToCampaign;
use App\Models\Beneficiary;
use App\Models\Campaign;
use App\Models\Donor;

/*
 | T-190 — «المشكلة التي كانت بالحالة بعدها موجودة بالحملات, نحتاجها تُطبَّق بكل
 | شيء فيه دفع بالمنصة: عند الدفع يظهر لك مواصلة العطاء أو إتمام التبرع»,
 | 29 October. And «إذا في مجال نغيّر كلمة مواصلة التبرع إلى مواصلة العطاء,
 | بكون أفضل».
 |
 | T-147 answered it for a family: the page does not move, and the donor is
 | offered the two ways on rather than having one taken for them. A campaign
 | was left with a line of text and nowhere to go, so a donor who had just
 | pledged had to find the basket themselves.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();

    $user = userWithRole('donor');
    $this->donor = Donor::factory()->create(['user_id' => $user->id]);
    $this->actingAs($user);
});

it('calls it «مواصلة العطاء», which is the word they chose', function () {
    expect(__('sanabel.public.continue_donating'))->toBe('مواصلة العطاء');
});

it('offers both ways on after a pledge to a campaign', function () {
    $campaign = Campaign::factory()->create([
        'is_published' => true,
        'status' => 'active',
        'goal_amount' => 100_000,
        'surplus_policy_text_ar' => 'يُحوَّل الفائض إلى الحملة التالية.',
    ]);

    Livewire\Livewire::test(PledgeToCampaign::class, ['campaign' => $campaign])
        ->assertSet('added', false)
        ->set('amount', 10_000)
        ->call('pledge')
        ->assertSet('added', true)
        ->assertSee(__('sanabel.public.continue_donating'))
        ->assertSee(__('sanabel.public.complete_donation'));
});

it('takes the donor back to the form without losing the page', function () {
    $campaign = Campaign::factory()->create([
        'is_published' => true,
        'status' => 'active',
        'goal_amount' => 100_000,
        'surplus_policy_text_ar' => 'يُحوَّل الفائض إلى الحملة التالية.',
    ]);

    Livewire\Livewire::test(PledgeToCampaign::class, ['campaign' => $campaign])
        ->set('amount', 10_000)
        ->call('pledge')
        ->call('keepGiving')
        ->assertSet('added', false)
        // The form is back, and nothing has been navigated away from.
        ->assertSee(__('sanabel.public.support_campaign'));
});

it('leaves the pledge in the basket when the donor carries on', function () {
    $campaign = Campaign::factory()->create([
        'is_published' => true,
        'status' => 'active',
        'goal_amount' => 100_000,
        'surplus_policy_text_ar' => 'يُحوَّل الفائض إلى الحملة التالية.',
    ]);

    Livewire\Livewire::test(PledgeToCampaign::class, ['campaign' => $campaign])
        ->set('amount', 10_000)
        ->call('pledge')
        ->call('keepGiving');

    expect($this->donor->baskets()->first()->items()->count())->toBe(1);
});

it('offers nothing of the kind before anything has been given', function () {
    $campaign = Campaign::factory()->create([
        'is_published' => true,
        'status' => 'active',
        'goal_amount' => 100_000,
        'surplus_policy_text_ar' => 'يُحوَّل الفائض إلى الحملة التالية.',
    ]);

    Livewire\Livewire::test(PledgeToCampaign::class, ['campaign' => $campaign])
        ->assertDontSee(__('sanabel.public.continue_donating'));
});

/*
 | T-191 — «SWA-0001: أرقام الملفات التي سنعتمدها», 29 October.
 */
it('numbers a new file the association’s way, and in one place only', function () {
    expect(Beneficiary::nextFileNumber())->toStartWith('SWA-')
        ->and(Beneficiary::nextFileNumber())->toMatch('/^SWA-\d{4,}$/');
});

it('moves the number on as files are opened', function () {
    $first = Beneficiary::nextFileNumber();

    familyOf($this->region, adults: 1, children: 0);

    expect(Beneficiary::nextFileNumber())->not->toBe($first);
});
