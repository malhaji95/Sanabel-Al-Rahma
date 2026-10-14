<?php

use App\Models\AppNotification;
use App\Models\Media;
use App\Models\Setting;
use App\Services\SecurityAlerts;
use Filament\Facades\Filament;

/*
 | T-95 — OPS-03, and AT-14 of the acceptance list: «محاكاة تسرب أو تغيير
 | صلاحية حرجة».
 |
 | Everything sensitive was already written to the audit log, and nobody was
 | told. A log read once a quarter is a record, not a control — so a short
 | list of writes now reaches somebody while they can still do something about
 | it: who may do what, what the system does to everybody, and what is
 | destroyed of a family's papers.
 |
 | Two halves, deliberately in two places. What raises an alert is ours, in
 | code, because a list the association could edit down to nothing is not a
 | control. Who hears it is theirs, in a setting — and until they say, nothing
 | is sent at all.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();

    // The association has named its oversight roles. Until it does, the list
    // is empty — which the last test here is about.
    Setting::put('security_alert_roles', ['council', 'board_director']);

    $this->watcher = userWithRole('council');
});

function alertsFor(int $recipient): \Illuminate\Support\Collection
{
    return AppNotification::query()
        ->where('recipient_id', $recipient)
        ->where('template_key', 'security_alert')
        ->get();
}

it('tells the oversight accounts when a role is changed on an account', function () {
    $staff = userWithRole('delegate', ['region_id' => $this->region->id]);

    $before = alertsFor($this->watcher->id)->count();

    $staff->forceFill(['role_id' => \App\Models\Role::where('key', 'case_officer')->value('id')])->save();

    expect(alertsFor($this->watcher->id)->count())->toBeGreaterThan($before);
})->group('OPS-03', 'AUTH-01');

it('tells them when an account is switched off, and when its password is set', function () {
    $staff = userWithRole('delegate', ['region_id' => $this->region->id]);

    $before = alertsFor($this->watcher->id)->count();
    $staff->forceFill(['is_active' => false])->save();
    $afterDeactivation = alertsFor($this->watcher->id)->count();

    expect($afterDeactivation)->toBeGreaterThan($before);

    $staff->forceFill(['password' => bcrypt('something-else')])->save();

    expect(alertsFor($this->watcher->id)->count())->toBeGreaterThan($afterDeactivation);
})->group('OPS-03');

it('tells them when a setting is changed, since one row changes a rule for everybody', function () {
    $before = alertsFor($this->watcher->id)->count();

    Setting::put('basket_hold_hours', 36);

    expect(alertsFor($this->watcher->id)->count())->toBeGreaterThan($before);
})->group('OPS-03');

it('says nothing about the record itself, only which record and what happened', function () {
    $staff = userWithRole('delegate', ['region_id' => $this->region->id]);
    $staff->forceFill(['is_active' => false])->save();

    $alert = alertsFor($this->watcher->id)->last();

    expect($alert)->not->toBeNull();

    $payload = json_encode($alert->payload_json, JSON_UNESCAPED_UNICODE);

    // Rule 10 — the alert carries a reference and an id. An address or a name
    // in an inbox is the leak the audit log was built to avoid.
    expect($payload)->toContain('User.updated')
        ->and($payload)->not->toContain($staff->email)
        ->and($payload)->not->toContain($staff->name);
})->group('OPS-03');

it('leaves the ordinary day alone, so an alert still means something', function () {
    $before = alertsFor($this->watcher->id)->count();

    // A family file, a visit, a donation: all audited, none of them a change
    // to who may do what. An alert on every write is an alert on nothing.
    publishedCase($this->region);

    expect(alertsFor($this->watcher->id)->count())->toBe($before);
})->group('OPS-03');

it('sends nothing at all while the association has named nobody', function () {
    // Emptying the list is itself one of the watched writes, and the people on
    // the list at that moment are told — which is the right way round: the
    // last thing the alerts do is report their own silencing.
    Setting::put('security_alert_roles', []);

    $onceSilenced = alertsFor($this->watcher->id)->count();

    expect($onceSilenced)->toBeGreaterThan(0);

    $staff = userWithRole('delegate', ['region_id' => $this->region->id]);
    $staff->forceFill(['is_active' => false])->save();

    // And after that, nothing. Not a silent failure: the mechanism is built
    // and tested, and waits on one decision that is not ours. An alert
    // addressed to nobody would read like a control that exists.
    expect(alertsFor($this->watcher->id)->count())->toBe($onceSilenced);
})->group('OPS-03');

it('watches the writes that change authority, and names them in one place', function () {
    // The list is code rather than a setting on purpose: a control the watched
    // party can edit down to nothing is not a control.
    expect(SecurityAlerts::WATCHED)->toHaveKeys([
        \App\Models\User::class,
        \App\Models\Setting::class,
        Media::class,
    ])->and(SecurityAlerts::WATCHED[\App\Models\User::class])
        ->toContain('role_id')
        ->toContain('is_active')
        ->toContain('password');
})->group('OPS-03');

it('offers the association the choice of who hears, on the settings screen', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    passTwoFactor();

    Livewire\Livewire::actingAs(userWithRole('admin'))
        ->test(\App\Filament\Pages\ManageSettings::class)
        ->assertSuccessful()
        ->fillForm(['security_alert_roles' => ['oversight_director']])
        ->call('save');

    expect(Setting::value('security_alert_roles'))->toBe(['oversight_director']);
})->group('OPS-03');
