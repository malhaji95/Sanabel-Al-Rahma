<?php

use App\Models\Campaign;

/*
 | T-117 — «عندنا موضوع القصة او المعلومات اكثر, نقدر نضيفها بي الحملات و
 | الحالات», 22 October.
 |
 | A family's file has carried its story since T-106. A campaign has carried
 | one all along in `body_ar` — and nowhere to read it: the list clamps the
 | text to three lines, because a card must, and there was no page behind the
 | card. So the association could type a story that no donor could reach the
 | end of.
 |
 | The page is the card with the clamp off, deliberately. A campaign's goal,
 | what it has collected and what is left are worked out in one place; a
 | second place to work them out is a second answer waiting to disagree.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
});

function storiedCampaign(array $attributes = []): Campaign
{
    return Campaign::factory()->create(array_merge([
        'beneficiary_id' => null,
        'title_ar' => 'ترميم سقف بيت في درعا',
        'body_ar' => "السطر الأول من القصة.\nوالسطر الرابع، الذي لا تصل إليه البطاقة.",
        'goal_amount' => 100_000,
        'status' => 'active',
        'is_published' => true,
        'surplus_policy_text_ar' => 'يوجه الفائض لحملة مماثلة.',
    ], $attributes));
}

it('gives a campaign an address of its own, with the whole story on it', function () {
    $campaign = storiedCampaign();

    $body = $this->get(route('campaign.public', $campaign))->assertOk()->getContent();

    expect($body)->toContain('ترميم سقف بيت في درعا')
        ->and($body)->toContain('والسطر الرابع، الذي لا تصل إليه البطاقة.')
        // The clamp is what the card does; the page is why the page exists.
        ->and($body)->not->toContain('line-clamp-3')
        // The line breaks the association typed are part of what they wrote.
        ->and($body)->toContain('whitespace-pre-line');
});

it('still shows the figures the list shows, from the same place', function () {
    $campaign = storiedCampaign(['goal_amount' => 250_000]);

    $page = $this->get(route('campaign.public', $campaign))->assertOk()->getContent();
    $list = $this->get(route('campaigns.public'))->assertOk()->getContent();

    foreach ([__('sanabel.public.goal'), __('sanabel.public.collected'), __('sanabel.public.remaining')] as $label) {
        expect($page)->toContain($label);
    }

    // The goal is one number on both, and the surplus policy is on both,
    // because a donor may pay from either (docs/03-rules.md §7).
    expect($page)->toContain(number_format(250_000))
        ->and($list)->toContain(number_format(250_000))
        ->and($page)->toContain('يوجه الفائض لحملة مماثلة.');
});

it('sends a reader from the list to the story, and shares the campaign rather than the list', function () {
    $campaign = storiedCampaign();

    $list = $this->get(route('campaigns.public'))->assertOk()->getContent();

    expect($list)->toContain(route('campaign.public', $campaign))
        ->and($list)->toContain(__('sanabel.public.open_campaign'));
});

it('does not publish a campaign the association has not published', function () {
    $campaign = storiedCampaign(['is_published' => false]);

    $this->get(route('campaign.public', $campaign))->assertNotFound();
});

it('keeps answering for a campaign that has closed, because the link is already out', function () {
    $campaign = storiedCampaign(['status' => 'completed']);

    $this->get(route('campaign.public', $campaign))
        ->assertOk()
        ->assertSee(__('sanabel.public.campaign_closed'));
});

it('tells the story without putting a family on the page', function () {
    /*
     | Rule 2 — a campaign page is a donor's screen. The family block beside
     | the story is the masked resource and nothing else, so what a campaign
     | adds here is the association's own words, never the household's.
     */
    $case = publishedCase($this->region, attributes: [
        'first_name' => 'محمد',
        'father_name' => 'أحمد',
        'family_name' => 'العلي',
        'phone_encrypted' => '0933123456',
    ]);
    $campaign = storiedCampaign(['beneficiary_id' => $case->id]);

    $body = $this->get(route('campaign.public', $campaign))->assertOk()->getContent();

    foreach (['العلي', '0933123456', $case->national_id_hash] as $identifying) {
        expect($body)->not->toContain($identifying);
    }

    // The file number is the one handle a donor is given, and the masked card
    // already carries it.
    expect($body)->toContain($case->file_number);
})->group('AUTH-02');
