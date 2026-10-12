<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\File;

/*
 | T-72 — the guards on the training tour.
 |
 | A training file that shows a screen the trainee does not have, or points at
 | a page that has moved, teaches the wrong thing and nobody notices until a
 | room full of staff is looking at it. So the guides are generated from the
 | live system, and these hold the parts the generator cannot check about
 | itself: that every role has an account, every step has a real route, and
 | every step says what it does, what is refused, and where the work goes.
 */

beforeEach(function () {
    seedCore();
});

function trainingGuides(): array
{
    return require resource_path('training/guides.php');
}

it('covers the ten roles that work in the system, each with its own guide', function () {
    $guides = trainingGuides();

    expect($guides)->toHaveCount(10);

    foreach ($guides as $key => $guide) {
        expect(trim($guide['name_ar'] ?? ''))->not->toBeEmpty("{$key} has no Arabic name")
            ->and(trim($guide['summary_ar'] ?? ''))->not->toBeEmpty("{$key} has no summary")
            ->and($guide['steps'] ?? [])->not->toBeEmpty("{$key} has no steps");
    }
});

it('names a role that really exists for every guide', function () {
    $roles = Role::pluck('key')->all();

    foreach (array_keys(trainingGuides()) as $key) {
        expect(in_array($key, $roles, true))->toBeTrue(
            "{$key} is a guide but not a role in the system"
        );
    }
})->group('AUTH-01');

it('tells every step what to do, so no screen is left as a picture alone', function () {
    foreach (trainingGuides() as $key => $guide) {
        foreach ($guide['steps'] as $step) {
            expect(trim($step['key'] ?? ''))->not->toBeEmpty("a step of {$key} has no key")
                ->and(trim($step['title_ar'] ?? ''))->not->toBeEmpty("{$step['key']} has no title")
                ->and(trim($step['do_ar'] ?? ''))->not->toBeEmpty("{$step['key']} does not say what to do")
                ->and(trim($step['path'] ?? ''))->toStartWith('/');
        }
    }
});

it('keeps every step key unique, or one screenshot would overwrite another', function () {
    $keys = collect(trainingGuides())->flatMap(fn ($guide) => collect($guide['steps'])->pluck('key'))->all();

    expect($keys)->toHaveCount(count(array_unique($keys)));
});

it('points every step at a route the application really has', function () {
    // A parameter is a parameter whatever it is called, here and in the route,
    // so both sides are reduced to a star before they are compared.
    $shape = function (string $uri): string {
        $uri = '/'.ltrim(preg_replace('/\{[^}]+\}/', '*', $uri), '/');

        return $uri === '/' ? '/' : rtrim($uri, '/');
    };

    $routes = collect(app('router')->getRoutes())
        ->map(fn ($route) => $shape($route->uri()))
        ->unique()
        ->all();

    foreach (trainingGuides() as $key => $guide) {
        foreach ($guide['steps'] as $step) {
            expect(in_array($shape($step['path']), $routes, true))->toBeTrue(
                "{$step['key']} points at {$step['path']}, which is not a route"
            );
        }
    }
});

it('explains what is refused on most steps, which is the half a menu cannot teach', function () {
    $steps = collect(trainingGuides())->flatMap(fn ($guide) => $guide['steps']);
    $refusing = $steps->filter(fn ($step) => ! empty($step['refuses_ar']));

    // Not every screen refuses something — a report does not. But a tour whose
    // steps mostly say only "click here" is a menu with pictures.
    expect($refusing->count())->toBeGreaterThan((int) ($steps->count() * 0.6));
});

it('asks the stage seeder for every record a step needs', function () {
    $needed = collect(trainingGuides())
        ->flatMap(fn ($guide) => collect($guide['steps'])->pluck('path'))
        ->map(fn ($path) => preg_match('/\{(?:case|file):([a-z_]+)\}/', $path, $m) ? $m[1] : null)
        ->filter()
        ->unique();

    $seeder = File::get(database_path('seeders/TrainingStageSeeder.php'));
    $demo = File::get(database_path('seeders/SyntheticDataSeeder.php'));

    foreach ($needed as $status) {
        expect(str_contains($seeder, "'{$status}'") || str_contains($demo, "'{$status}'"))->toBeTrue(
            "a step needs a case at status {$status} and no seeder leaves one there"
        );
    }
});

it('opens an account for every guide, in a seeder and not by hand', function () {
    $seeder = File::get(database_path('seeders/TrainingStageSeeder.php'));
    $demo = File::get(database_path('seeders/SyntheticDataSeeder.php'));

    foreach (trainingGuides() as $key => $guide) {
        if (empty($guide['account'])) {
            continue;
        }

        expect(str_contains($seeder, $guide['account']) || str_contains($demo, $guide['account']))->toBeTrue(
            "{$key} signs in as {$guide['account']}, which no seeder creates"
        );
    }
});

it('asks for a second factor exactly where the system demands one', function () {
    foreach (trainingGuides() as $key => $guide) {
        $needs = in_array($key, User::TWO_FACTOR_ROLES, true);

        expect((bool) ($guide['two_factor'] ?? false))->toBe(
            $needs,
            "{$key} ".($needs ? 'needs' : 'does not need').' a second factor, and the guide says otherwise'
        );
    }
})->group('IAM-01');

it('renders a guide with its steps, its refusals and the commit it came from', function () {
    $guides = trainingGuides();
    $guide = $guides['delegate'];

    $html = view('training.guide', [
        'role' => 'delegate',
        'guide' => $guide,
        'steps' => collect($guide['steps'])->map(fn ($step) => $step + ['image' => null])->all(),
        'generatedAt' => now(),
        'commit' => 'abc1234',
    ])->render();

    expect($html)->toContain($guide['name_ar'])
        ->and($html)->toContain($guide['summary_ar'])
        ->and($html)->toContain(__('training.what_is_refused'))
        ->and($html)->toContain('abc1234')
        ->and($html)->toContain('dir="rtl"')
        // No screenshot for a step says so, rather than leaving a blank frame.
        ->and($html)->toContain(__('training.no_screenshot'));

    foreach ($guide['steps'] as $step) {
        expect($html)->toContain($step['title_ar']);
    }
});
