<?php

use App\Filament\Resources\DonationResource;
use App\Filament\Resources\JobProfileResource;
use App\Filament\Resources\SponsorshipResource;
use App\Models\Donation;
use App\Models\Donor;
use Filament\Facades\Filament;

/*
 | T-202 — «ضمن لوحة التحكم مسجّل دخول بحساب المدير التنفيذي لكن حاليًا لا
 | يوجد tab التبرعات ضمن القائمة. يجب على المدير التنفيذي رؤية كل شيء كي
 | يستطيع متابعة عمل الجميع لمعرفة في حال تأخر أي حدث في سير العملية»,
 | 1 November, naming التبرعات والكفالات وسوق العمل.
 |
 | Since T-115 the key to a screen is the key to the thing on it. The
 | executive director holds every approval over money and none of the keys
 | that open the screens money queues on, so the three screens where other
 | people's work piles up were the three they could not see.
 |
 | They join the overseers: the screens open, and nothing else does.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->director = userWithRole('executive_director');
    passTwoFactor();
    $this->actingAs($this->director);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('opens the three screens the association named', function () {
    foreach ([DonationResource::class, SponsorshipResource::class, JobProfileResource::class] as $resource) {
        expect($resource::canViewAny())->toBeTrue($resource.' is still closed to the director');
    }
});

it('reads a donation, whoever it belongs to and whatever it is marked', function () {
    $donation = Donation::factory()->create([
        'donor_id' => Donor::factory()->create()->id,
        'designation' => 'general',
        'status' => 'pending',
    ]);

    expect($this->director->can('view', $donation))->toBeTrue();
});

it('is given no key it did not have: reading is not verifying', function () {
    $donation = Donation::factory()->create([
        'donor_id' => Donor::factory()->create()->id,
        'status' => 'pending',
    ]);

    // «يجب أن يرى كل شيء» is about following the work, not doing it. The
    // treasurer still verifies the money.
    expect($this->director->can('verify', $donation))->toBeFalse();
});

it('leaves the roles that could not see them unable to see them', function () {
    $delegate = userWithRole('delegate', ['region_id' => $this->region->id]);
    $this->actingAs($delegate);

    expect(DonationResource::canViewAny())->toBeFalse();
});
