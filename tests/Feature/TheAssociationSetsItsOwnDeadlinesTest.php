<?php

use App\Filament\Pages\ApprovalFollowUp;
use App\Models\Setting;
use App\Services\CaseService;
use Filament\Facades\Filament;

/*
 | T-316 — phase two, item 2: «مُهَل الاستجابة».
 |
 | The two lines the association drew on 26 October — twenty-four hours and
 | forty-eight — were constants in the page that draws them. They are
 | reference amounts, and a reference amount is data (§5): these are the hours
 | by which the association judges its own desks, so it has to be able to move
 | them without us, and the column heading has to follow when it does.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->delegate = userWithRole('delegate', ['region_id' => placeIn($this->region, 'village')->id]);
    passTwoFactor();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

/** A file the delegate opened and has not visited, this many hours ago. */
function waitingFor(int $hours): void
{
    $case = familyOf(test()->region, adults: 1, children: 0);

    $case->forceFill([
        'created_by' => test()->delegate->id,
        'status' => CaseService::AWAITING_FIELD[0],
        'created_at' => now()->subHours($hours),
    ])->saveQuietly();
}

it('starts on the hours the association agreed', function () {
    expect(ApprovalFollowUp::lateHours())->toBe(24)
        ->and(ApprovalFollowUp::veryLateHours())->toBe(48);
});

it('counts the bands by the hours in the settings, not by the hours in the code', function () {
    waitingFor(10);
    waitingFor(30);
    waitingFor(70);

    $row = collect((new ApprovalFollowUp)->awaitingDelegate())
        ->firstWhere('label', $this->delegate->name);

    expect($row['total'])->toBe(3)
        ->and($row['late'])->toBe(1)
        ->and($row['very_late'])->toBe(1);

    // The association tightens both lines: eight hours, then twelve.
    Setting::updateOrCreate(['key' => 'follow_up_late_hours'], ['value_json' => 8]);
    Setting::updateOrCreate(['key' => 'follow_up_very_late_hours'], ['value_json' => 12]);

    $row = collect((new ApprovalFollowUp)->awaitingDelegate())
        ->firstWhere('label', $this->delegate->name);

    // Now the ten-hour file is late and the other two are very late.
    expect($row['total'])->toBe(3)
        ->and($row['late'])->toBe(1)
        ->and($row['very_late'])->toBe(2);
});

it('keeps the second line above the first, however it is set', function () {
    /*
     | Two lines at the same hour leave the middle band empty for ever, and a
     | second line below the first puts every late file in both bands at once.
     */
    Setting::updateOrCreate(['key' => 'follow_up_late_hours'], ['value_json' => 48]);
    Setting::updateOrCreate(['key' => 'follow_up_very_late_hours'], ['value_json' => 48]);

    expect(ApprovalFollowUp::veryLateHours())->toBe(49);

    Setting::updateOrCreate(['key' => 'follow_up_very_late_hours'], ['value_json' => 6]);

    expect(ApprovalFollowUp::veryLateHours())->toBe(49);

    // And nothing may be judged late before it has existed for an hour.
    Setting::updateOrCreate(['key' => 'follow_up_late_hours'], ['value_json' => 0]);

    expect(ApprovalFollowUp::lateHours())->toBe(1);
});

it('writes the hour it was set to on the column heading', function () {
    Setting::updateOrCreate(['key' => 'follow_up_late_hours'], ['value_json' => 8]);
    Setting::updateOrCreate(['key' => 'follow_up_very_late_hours'], ['value_json' => 12]);

    waitingFor(30);

    $this->actingAs(userWithRole('executive_director'));

    $html = Livewire\Livewire::test(ApprovalFollowUp::class)->html();

    expect($html)->toContain(__('sanabel.follow_up.late', ['hours' => 8]))
        ->and($html)->toContain(__('sanabel.follow_up.very_late', ['hours' => 12]))
        // And no longer the hours that used to be written into the page.
        ->and($html)->not->toContain(__('sanabel.follow_up.late', ['hours' => 24]));
});

it('puts both lines on the settings screen, for the association to move', function () {
    $defaults = config('sanabel.setting_defaults');

    expect($defaults)->toHaveKey('follow_up_late_hours')
        ->and($defaults)->toHaveKey('follow_up_very_late_hours')
        // Integer defaults are what the settings form draws itself from.
        ->and($defaults['follow_up_late_hours'])->toBeInt()
        ->and($defaults['follow_up_very_late_hours'])->toBeInt()
        // And each has an Arabic label, or the screen would print the key.
        ->and(__('sanabel.settings.keys.follow_up_late_hours'))->not->toBe('sanabel.settings.keys.follow_up_late_hours')
        ->and(__('sanabel.settings.keys.follow_up_very_late_hours'))->not->toBe('sanabel.settings.keys.follow_up_very_late_hours');
});
