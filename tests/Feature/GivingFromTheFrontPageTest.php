<?php

use App\Livewire\AddToBasket;
use App\Models\Donor;

/*
 | T-207 — «هون ما في يحط المبلغ ويحط إضافة بالسلة… هون احنا نحتاج متل هيك,
 | إنه يقدر يحط المبلغ», 30 October, filmed on a phone: the list at /cases
 | carries the box, the three cards on the front page do not.
 |
 | It was never a fault, it was a difference. The front page offered «المزيد
 | من المعلومات» and the «تبرع سريع» tile beside it, which goes to the
 | general donation page — a different thing, and not a substitute for giving
 | to the family on the card in front of you.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    publishedCase($this->region);
});

it('offers the box on the front page to a signed-in donor', function () {
    $user = userWithRole('donor');
    Donor::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)->get('/')
        ->assertOk()
        ->assertSeeLivewire(AddToBasket::class);
});

it('offers a visitor the file instead, as it always did', function () {
    // Nothing is promised to somebody who cannot yet give: the card still
    // leads to the family's page, and the sign-in line is on it.
    $this->get('/')
        ->assertOk()
        ->assertSee(__('sanabel.public.more_info'));
});

it('still leads to the family’s own page from the card', function () {
    $user = userWithRole('donor');
    Donor::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)->get('/')
        ->assertOk()
        ->assertSee(__('sanabel.public.more_info'));
});
