<?php

use App\Models\Beneficiary;
use App\Models\Role;
use App\Models\User;
use App\Services\PermissionService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

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

function trainingOverview(): array
{
    return require resource_path('training/overview.php');
}

it('covers the twelve roles that work in the system, each with its own guide', function () {
    $guides = trainingGuides();

    expect($guides)->toHaveCount(12);

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
        'sheet' => app(PermissionService::class)->roleSheet('delegate'),
        'matrix' => null,
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

/*
 | T-92 — the three guards the new sheets need.
 |
 | The first is the one that was missing all along: a step is a promise that
 | the account the guide belongs to can open that screen. The content
 | manager's campaigns step was a 403 for as long as it existed, and nothing
 | said so until a human signed in and looked.
 */

it('opens every step of every guide for the account that guide belongs to', function () {
    $region = regionWithRates();
    passTwoFactor();

    // One file standing at each stage a step names, so a record page is a
    // record page and not a redirect away from a missing row.
    $cases = [];

    foreach (['draft', 'pending_visit', 'verified', 'pending_approval'] as $status) {
        $cases[$status] = familyOf($region, attributes: ['status' => $status]);
    }

    $cases['published'] = publishedCase($region);

    $resolve = function (string $path) use ($cases): string {
        if (! preg_match('/\{(case|file):([a-z_]+)\}/', $path, $m)) {
            return $path;
        }

        /** @var Beneficiary $case */
        $case = $cases[$m[2]];

        return str_replace($m[0], $m[1] === 'case' ? (string) $case->getKey() : $case->file_number, $path);
    };

    foreach (trainingGuides() as $key => $guide) {
        $user = userWithRole($key, ['region_id' => $region->id]);

        if ($panel = $guide['panel'] ?? null) {
            Filament::setCurrentPanel(Filament::getPanel($panel));
        }

        /*
         | A panel keeps the session tied to the account that opened it, so a
         | second account reusing the same session is signed out on sight —
         | correctly. Each guide therefore starts on a clean session, with the
         | second-factor gate cleared again (SecurityTest owns the gate; here
         | it is a precondition).
         */
        $this->flushSession();
        passTwoFactor();

        foreach ($guide['steps'] as $step) {
            $path = $resolve($step['path']);
            $status = $this->actingAs($user)->get($path)->getStatusCode();

            // A denied Filament screen redirects rather than answering 403,
            // so anything but 200 means the trainee would not see what the
            // guide photographs.
            expect($status)->toBe(200, "{$step['key']} answered {$status} at {$path} for {$key}");
        }
    }
})->group('AUTH-01');

it('shows the overview document only screens every visitor has', function () {
    $overview = trainingOverview();

    // Signed in as nobody: these are the two screens that come before an
    // account exists, and /login is only served to a guest.
    foreach ($overview['steps'] as $step) {
        $this->get($step['path'])->assertSuccessful();
    }

    expect($overview['steps'])->toHaveCount(2)
        ->and(collect($overview['steps'])->firstWhere('path', '/login')['login'])->toBeTrue();
});

it('writes the overview document with its services, its words and its doors', function () {
    $overview = trainingOverview();

    foreach (['journey_ar', 'services', 'doors', 'glossary', 'first_login', 'help'] as $section) {
        expect($overview[$section] ?? [])->not->toBeEmpty("the overview has no {$section}");
    }

    foreach ($overview['services'] as $service) {
        expect(trim($service['title_ar']))->not->toBeEmpty()
            ->and(trim($service['body_ar']))->not->toBeEmpty();
    }

    foreach ($overview['glossary'] as $term) {
        expect(trim($term['term_ar']))->not->toBeEmpty()
            ->and(trim($term['body_ar']))->not->toBeEmpty();
    }

    // Every door is a route, so no trainee is sent to an address that moved.
    $routes = collect(app('router')->getRoutes())->map(fn ($route) => '/'.ltrim($route->uri(), '/'))->all();

    foreach ($overview['doors'] as $door) {
        expect(in_array(rtrim($door['path'], '/') ?: '/', $routes, true))->toBeTrue(
            "the overview sends {$door['who_ar']} to {$door['path']}, which is not a route"
        );
    }
});

it('builds the permissions annex from the rows the policies read, not from prose', function () {
    $permissions = app(PermissionService::class);

    // The delegate: holds the field step scoped to their area, and is refused
    // the approval and the money — which is the whole point of the annex.
    $delegate = $permissions->roleSheet('delegate');

    expect(collect($delegate['held']['write'])->firstWhere('name_ar', __('sanabel.permissions.keys.record_visit'))['scope'])
        ->toBe('area')
        ->and($delegate['refused']['write'])->toContain(__('sanabel.permissions.keys.approve_case'))
        ->and($delegate['refused']['write'])->toContain(__('sanabel.permissions.keys.verify_payment'))
        ->and($delegate['read_only'])->toBeFalse();

    // Hard rule 1 on the page: a read-only role holds no write at all, and
    // the annex says so rather than printing what the pivot stores.
    $council = $permissions->roleSheet('council');

    expect($council['read_only'])->toBeTrue()
        ->and($council['held']['write'])->toBeEmpty()
        ->and($council['held']['read'])->not->toBeEmpty()
        ->and($council['refused']['write'])->toHaveCount(count(PermissionService::WRITE_PERMISSIONS));
})->group('AUTH-01');

it('ends a guide with what the account may do, and the whole matrix where it is owed', function () {
    $guides = trainingGuides();
    $guide = $guides['executive_director'];
    $permissions = app(PermissionService::class);

    $matrix = [
        'roles' => [['name_ar' => 'المدير التنفيذي', 'read_only' => false]],
        'groups' => ['write' => [['name_ar' => 'اعتماد', 'cells' => ['all']]], 'read' => []],
    ];

    $html = view('training.guide', [
        'role' => 'executive_director',
        'guide' => $guide,
        'steps' => collect($guide['steps'])->map(fn ($step) => $step + ['image' => null])->all(),
        'sheet' => $permissions->roleSheet('executive_director'),
        'matrix' => $matrix,
        'generatedAt' => now(),
        'commit' => 'abc1234',
    ])->render();

    expect($html)->toContain(__('training.permissions.title'))
        ->and($html)->toContain(__('training.permissions.refused'))
        // The approval it holds, with its scope, and a refusal it does not.
        ->and($html)->toContain(__('sanabel.permissions.keys.approve_case'))
        ->and($html)->toContain(__('sanabel.permissions.keys.manage_users'))
        ->and($html)->toContain(__('sanabel.matrix.title'))
        ->and($html)->toContain(__('training.read_overview_first'));

    // And a guide without the flag does not carry the matrix sheet.
    $delegate = $guides['delegate'];

    $plain = view('training.guide', [
        'role' => 'delegate',
        'guide' => $delegate,
        'steps' => collect($delegate['steps'])->map(fn ($step) => $step + ['image' => null])->all(),
        'sheet' => $permissions->roleSheet('delegate'),
        'matrix' => null,
        'generatedAt' => now(),
        'commit' => 'abc1234',
    ])->render();

    expect($plain)->toContain(__('training.permissions.title'))
        ->and($plain)->not->toContain(__('sanabel.matrix.intro'));
});

it('renders the overview document with every section and the matrix', function () {
    $overview = trainingOverview();
    $guides = trainingGuides();

    $html = view('training.overview', [
        'overview' => $overview,
        'steps' => collect($overview['steps'])->map(fn ($step) => $step + ['image' => null])->all(),
        'roles' => collect($guides)->map(fn ($guide) => [
            'name_ar' => $guide['name_ar'],
            'steps' => count($guide['steps']),
            'summary_ar' => Str::before($guide['summary_ar'], '.').'.',
        ])->values()->all(),
        'matrix' => [
            'roles' => [['name_ar' => 'مدير النظام', 'read_only' => false]],
            'groups' => ['write' => [['name_ar' => 'إدارة المستخدمين والأدوار', 'cells' => ['all']]], 'read' => []],
        ],
        'generatedAt' => now(),
        'commit' => 'abc1234',
    ])->render();

    expect($html)->toContain('dir="rtl"')
        ->and($html)->toContain(__('training.overview.journey'))
        ->and($html)->toContain(__('training.overview.services'))
        ->and($html)->toContain(__('training.overview.glossary'))
        ->and($html)->toContain(__('training.overview.first_login'))
        ->and($html)->toContain(__('training.overview.help'))
        ->and($html)->toContain(__('sanabel.matrix.title'))
        // Every guide appears in the table of guides, so the list cannot fall
        // behind the guides themselves.
        ->and($html)->toContain($guides['admin']['name_ar'])
        ->and($html)->toContain($guides['association']['name_ar'])
        ->and($html)->toContain('abc1234');
});
