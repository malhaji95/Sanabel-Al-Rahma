<?php

use App\Console\Commands\DemoPasswords;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/*
 | T-114 — the association set SEED_ADMIN_PASSWORD and SEED_DEMO_PASSWORD on
 | 20 October and still could not sign in.
 |
 | Every seeder creates its accounts with firstOrCreate, which is right and
 | which means a password is hashed exactly once — when the row is first
 | written — and no later deploy touches it. The two variables therefore only
 | ever described the accounts created on the deploy that first set them.
 |
 | These hold the narrow fix: the stated password becomes true, without
 | emptying the database to make it so, and without reaching an account that
 | is not part of the demonstration.
 */

beforeEach(function () {
    seedCore();
    config()->set('sanabel.demo_seed', true);
});

function demoUser(string $email, string $password, string $role = 'delegate'): User
{
    return User::create([
        'name' => 'حساب عرض',
        'email' => $email,
        'password' => Hash::make($password),
        'role_id' => Role::where('key', $role)->value('id'),
        'is_active' => true,
    ]);
}

it('makes the stated password true for an account seeded with an older one', function () {
    $user = demoUser('delegate@sanabel.local', 'whatever-the-first-deploy-used');

    expect(Hash::check('Sanabel@Test@111', $user->password))->toBeFalse();

    withEnv(['SEED_DEMO_PASSWORD' => 'Sanabel@Test@111'], fn () => runArtisan('demo:passwords'));

    expect(Hash::check('Sanabel@Test@111', $user->refresh()->password))->toBeTrue();
});

it('gives the administrator its own variable, and everyone else the demo one', function () {
    $admin = demoUser(DemoPasswords::ADMIN_EMAIL, 'old', 'admin');
    $officer = demoUser('officer@sanabel.local', 'old', 'case_officer');

    withEnv([
        'SEED_ADMIN_PASSWORD' => 'Admin-One',
        'SEED_DEMO_PASSWORD' => 'Demo-One',
    ], fn () => runArtisan('demo:passwords'));

    expect(Hash::check('Admin-One', $admin->refresh()->password))->toBeTrue()
        ->and(Hash::check('Demo-One', $officer->refresh()->password))->toBeTrue()
        // Not the other way round, which would lock the panel out of itself.
        ->and(Hash::check('Demo-One', $admin->password))->toBeFalse();
});

it('never touches an account that is not one of the seeded ones', function () {
    /*
     | A demonstration that later carries a real person's account must not
     | have that person's password rewritten by a deploy. The seeded accounts
     | all live on one domain and nothing else does.
     */
    $real = demoUser('someone@association.example', 'their own password');

    withEnv(['SEED_DEMO_PASSWORD' => 'Demo-One'], fn () => runArtisan('demo:passwords'));

    expect(Hash::check('their own password', $real->refresh()->password))->toBeTrue();
});

it('refuses to run anywhere that is not a demonstration', function () {
    config()->set('sanabel.demo_seed', false);

    $user = demoUser('delegate@sanabel.local', 'untouched');

    withEnv(['SEED_DEMO_PASSWORD' => 'Demo-One'], fn () => runArtisan('demo:passwords', failing: true));

    expect(Hash::check('untouched', $user->refresh()->password))->toBeTrue();
});

it('writes nothing when the passwords already match', function () {
    $user = demoUser('delegate@sanabel.local', 'Demo-One');
    $before = $user->updated_at;

    $this->travel(2)->minutes();

    withEnv(['SEED_DEMO_PASSWORD' => 'Demo-One'], fn () => runArtisan('demo:passwords'));

    // A deploy that changes no password leaves no audit entries behind it.
    expect($user->refresh()->updated_at->equalTo($before))->toBeTrue();
});

it('changes nothing when neither variable is set', function () {
    $user = demoUser('delegate@sanabel.local', 'untouched');

    withEnv(['SEED_ADMIN_PASSWORD' => null, 'SEED_DEMO_PASSWORD' => null],
        fn () => runArtisan('demo:passwords'));

    expect(Hash::check('untouched', $user->refresh()->password))->toBeTrue();
});

it('says what it would do without doing it', function () {
    $user = demoUser('delegate@sanabel.local', 'untouched');

    withEnv(['SEED_DEMO_PASSWORD' => 'Demo-One'],
        fn () => runArtisan('demo:passwords', ['--dry-run' => true]));

    expect(Hash::check('untouched', $user->refresh()->password))->toBeTrue();
});
