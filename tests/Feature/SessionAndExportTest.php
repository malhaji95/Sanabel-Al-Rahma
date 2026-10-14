<?php

use App\Models\Donor;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;

/*
 | T-93 — three items of the security file that were «جزئي» because of what
 | was missing, not because of what was wrong.
 |
 |   IAM-02  a password change and a deactivation must end the sessions that
 |           are already open, not wait for them to expire.
 |   IAM-03  a failed sign-in must say the same thing whether the account
 |           exists or not, so the form cannot be used to collect addresses.
 |   AUTH-04 nothing in the system may hand over more than a page, and there
 |           is no export at all in phase one — which only stays true if
 |           something fails the day one is added without an audit trail.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
});

function signedInDonor(): User
{
    $user = userWithRole('donor');
    Donor::factory()->create(['user_id' => $user->id, 'email' => $user->email]);

    return $user;
}

it('ends the sessions already open when the password changes', function () {
    $user = signedInDonor();

    // Signed in, and reading their own portal.
    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
    $this->get('/portal')->assertSuccessful();

    // An administrator sets a new password — the one lever they have over an
    // account they suspect.
    $user->forceFill(['password' => Hash::make('a-new-password')])->save();

    /*
     | A real next request reaches a process that knows nothing yet; the test
     | harness keeps one application alive across requests, so the guard would
     | otherwise answer from the user object it is already holding and never
     | re-read the row. Forgetting the guards is what makes the second request
     | below a second request.
     */
    $this->app['auth']->forgetGuards();

    // The open session dies on its next request rather than living out its
    // two hours.
    $this->get('/portal')->assertRedirect(route('login'));
    expect(auth()->check())->toBeFalse();
})->group('IAM-02');

it('ends the session the moment an account is switched off', function () {
    $user = signedInDonor();

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
    $this->get('/portal')->assertSuccessful();

    $user->forceFill(['is_active' => false])->save();
    $this->app['auth']->forgetGuards();

    $this->get('/portal')->assertRedirect(route('login'));
    expect(auth()->check())->toBeFalse();
})->group('IAM-02');

it('refuses a switched-off account on the API too, and says nothing about it', function () {
    $user = signedInDonor();

    $this->actingAs($user)->getJson('/api/donor/cases/monthly')->assertSuccessful();

    $user->forceFill(['is_active' => false])->save();
    $this->app['auth']->forgetGuards();

    $response = $this->actingAs($user)->getJson('/api/donor/cases/monthly');

    $response->assertStatus(401);
    // Rule 10 — no address, no name, no role in the refusal.
    expect($response->json('message'))->not->toContain($user->email);
})->group('IAM-02');

it('answers a failed sign-in the same way whether the account exists or not', function () {
    $user = signedInDonor();

    $unknown = $this->post('/login', ['email' => 'nobody-at-all@example.test', 'password' => 'wrong']);
    $known = $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);

    // Same message, so the form cannot be used to find out who has an account.
    expect($known->getSession()->get('errors')->first('email'))
        ->toBe($unknown->getSession()->get('errors')->first('email'))
        ->toBe(__('sanabel.auth.failed'));
})->group('IAM-03');

it('does not say an account is switched off until the password was right', function () {
    $user = signedInDonor();
    $user->forceFill(['is_active' => false])->save();

    // Wrong password on a switched-off account: the generic refusal, because
    // «this account is disabled» to someone who has not proved anything is a
    // way of confirming the address.
    $wrong = $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);
    expect($wrong->getSession()->get('errors')->first('email'))->toBe(__('sanabel.auth.failed'));

    // The right password, and only then, is told why it is not enough.
    $right = $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    expect($right->getSession()->get('errors')->first('email'))->toBe(__('sanabel.auth.inactive'))
        ->and(auth()->check())->toBeFalse();
})->group('IAM-03');

it('hands over a page at a time however large a page is asked for', function () {
    foreach (range(1, 8) as $i) {
        publishedCase($this->region);
    }

    // The donor API: a page is capped whatever the request says.
    $donor = signedInDonor();
    $cases = $this->actingAs($donor)->getJson('/api/donor/cases/monthly?per_page=5000');
    $cases->assertSuccessful();
    expect(count($cases->json('data')))->toBeLessThanOrEqual(50);

    // And the internal one, which reads whole files rather than masked cards.
    $officer = userWithRole('case_officer', ['region_id' => $this->region->id]);
    $internal = $this->actingAs($officer)->getJson('/api/cases?per_page=5000');
    $internal->assertSuccessful();
    expect(count($internal->json('data')))->toBeLessThanOrEqual(20);
})->group('AUTH-04');

it('has no export anywhere, which is the only reason none is audited', function () {
    /*
     | AUTH-04 asks that every export be written to the audit log and capped.
     | Phase one answers it by having no export: the lists are paginated and
     | region-scoped, and the only files the panels hand out are the two empty
     | reference templates — column headings, not rows.
     |
     | That is a true answer only for as long as it stays true, so this fails
     | the day someone adds a download without the audit entry that AUTH-04
     | asks for, and the item re-opens by itself.
     */
    $offenders = [];

    foreach (File::allFiles(app_path('Filament')) as $file) {
        $source = File::get($file->getPathname());

        foreach (['ExportAction', 'ExportBulkAction', 'exportable()'] as $needle) {
            if (str_contains($source, $needle)) {
                $offenders[] = $file->getFilename().' → '.$needle;
            }
        }

        // A streamed download is allowed only where it carries no record: the
        // import templates are headings alone.
        if (str_contains($source, 'streamDownload') && ! str_contains($source, 'Template()')) {
            $offenders[] = $file->getFilename().' → streamDownload';
        }
    }

    expect($offenders)->toBeEmpty(
        'a download was added without the audit entry AUTH-04 asks for: '.implode('، ', $offenders)
    );
})->group('AUTH-04');
