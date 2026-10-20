<?php

use App\Models\Campaign;
use App\Models\ContentCard;
use App\Models\Disbursement;
use App\Models\DisbursementOrder;
use App\Models\Distribution;
use App\Models\Member;
use App\Models\Page;
use App\Models\Provider;
use App\Models\RegionRate;
use App\Models\ScoringWeight;
use App\Models\Setting;
use App\Models\Sponsorship;

/*
 | T-115 — «هل الأفضل إخفاء ما يجب إخفاؤه على حسب الصلاحية؟», 20 October.
 |
 | Yes, and the measurement is why. `view_reports` is held by almost every
 | role — a delegate holds it, a donor holds it, a partner association holds
 | it — and twenty-five policies opened their screen on it. So it had become
 | a master key: the area supervisor was shown twenty-one screens and could
 | act on one; the accountant was shown twenty-one and could create in none.
 |
 | Reading a list is not a report. The key to a screen is the key to the
 | thing on it, and `view_reports` keeps the reports it was named for.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
});

it('shows the area supervisor the home page content and lets them change none of it', function () {
    // The association's own question, in the two halves they asked it in.
    $supervisor = userWithRole('area_supervisor', ['region_id' => $this->region->id]);
    $card = ContentCard::create([
        'block' => 'tile',
        'title_ar' => 'باب عطاء',
        'is_published' => true,
    ]);

    expect($supervisor->can('update', $card))->toBeFalse()
        ->and($supervisor->can('create', ContentCard::class))->toBeFalse()
        ->and($supervisor->can('delete', $card))->toBeFalse()
        // And now it is not shown to them at all, which is the half of the
        // question they answered themselves.
        ->and($supervisor->can('viewAny', ContentCard::class))->toBeFalse();
})->group('AUTH-01');

it('stops view_reports from being a key to screens that are not reports', function () {
    /*
     | The regression this file exists for: the clause was one line, copied
     | into twenty-five policies, and every one of them was wrong in the same
     | way. A role that holds `view_reports` and nothing else opens nothing.
     */
    $delegate = userWithRole('delegate', ['region_id' => $this->region->id]);

    expect($delegate->can_('view_reports'))->toBeTrue();

    foreach ([
        ContentCard::class, Page::class, Member::class, Provider::class,
        Sponsorship::class, Disbursement::class, Setting::class,
        RegionRate::class, ScoringWeight::class,
        Campaign::class, Distribution::class,
    ] as $model) {
        expect($delegate->can('viewAny', $model))
            ->toBeFalse('a delegate still opens '.class_basename($model));
    }
})->group('AUTH-01', 'AUTH-02');

it('leaves every screen open to the accounts whose job is to look', function () {
    // Hard rule 1 — the board of trustees writes nothing and oversees
    // everything, so taking the master key away must not blind them.
    $council = userWithRole('council');

    foreach ([ContentCard::class, Member::class, Disbursement::class, Provider::class] as $model) {
        expect($council->can('viewAny', $model))->toBeTrue(class_basename($model).' closed to the council')
            ->and($council->can('create', $model))->toBeFalse(class_basename($model).' is writable by the council');
    }
})->group('AUTH-01');

it('keeps each screen open to the people who work in it', function () {
    // Taking a key away is only right if the work still gets done.
    expect(userWithRole('content_manager')->can('viewAny', ContentCard::class))->toBeTrue()
        ->and(userWithRole('content_manager')->can('update', ContentCard::create([
            'block' => 'tile', 'title_ar' => 'باب', 'is_published' => true,
        ])))->toBeTrue();

    // The money path: the accountant reconciles, so the ledger is theirs to
    // read even though they create nothing on it.
    expect(userWithRole('finance')->can('viewAny', Disbursement::class))->toBeTrue()
        ->and(userWithRole('treasurer')->can('viewAny', DisbursementOrder::class))->toBeTrue();

    // And the settings belong to the keys that edit configuration.
    expect(userWithRole('executive_director')->can('viewAny', Setting::class))->toBeTrue()
        ->and(userWithRole('delegate')->can('viewAny', Setting::class))->toBeFalse();
})->group('AUTH-01');

it('cuts what a working role is shown down to what it works in', function () {
    /*
     | The measurement itself, so the drift is caught rather than described.
     | Before this change the area supervisor was offered twenty-one screens;
     | the figure below is a ceiling, not a target.
     */
    $resources = collect(glob(app_path('Filament/Resources/*.php')))
        ->map(fn (string $file) => 'App\\Filament\\Resources\\'.basename($file, '.php'))
        ->filter(fn (string $class) => class_exists($class) && method_exists($class, 'getModel'))
        ->map(fn (string $class) => $class::getModel())
        ->filter(fn (string $model) => class_exists($model));

    expect($resources->count())->toBeGreaterThan(15);

    foreach (['area_supervisor', 'delegate', 'donor', 'service_provider'] as $roleKey) {
        $user = userWithRole($roleKey, ['region_id' => $this->region->id]);
        $seen = $resources->filter(fn (string $model) => $user->can('viewAny', $model));

        expect($seen->count())->toBeLessThanOrEqual(6, $roleKey.' is shown '.$seen->count().' screens');
    }
})->group('AUTH-01', 'AUTH-02');
