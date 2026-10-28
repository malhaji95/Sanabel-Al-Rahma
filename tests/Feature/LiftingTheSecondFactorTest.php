<?php

use App\Filament\Pages\TwoFactor;
use App\Filament\Resources\BeneficiaryResource;
use App\Http\Middleware\RequireTwoFactor;
use App\Models\ChangeRequest;
use App\Models\Setting;
use App\Models\User;
use App\Services\Totp;
use Filament\Facades\Filament;

/*
 | T-179 — «اعمل إيقاف للتحقق بخطوتين بشكل مؤقت», 28 October.
 |
 | T-38 put a TOTP gate in front of every panel screen for the four roles that
 | hold the whole system, and there was no way to lift it for an afternoon of
 | training without editing the code. There is now, and these are the four
 | things that make lifting it safe rather than merely possible: it is on
 | unless the server says otherwise, it cannot be reached from inside the
 | panel, it says so on every screen while it is off, and putting it back
 | needs nothing but the line removed.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

/** An account that has already set a second factor up and cleared it today. */
function enrolledAdmin(): User
{
    $admin = userWithRole('admin');

    $admin->forceFill([
        'two_factor_secret' => app(Totp::class)->generateSecret(),
        'two_factor_confirmed_at' => now(),
    ])->save();

    passTwoFactor();

    return $admin;
}

it('is enforced when nobody has said otherwise', function () {
    // The default in config/sanabel.php, read as the application reads it.
    expect(RequireTwoFactor::enforced())->toBeTrue();

    $this->actingAs(userWithRole('admin'))
        ->get(BeneficiaryResource::getUrl('index'))
        ->assertRedirect(TwoFactor::getUrl(panel: 'admin'));
})->group('IAM-01');

it('diverts none of the four roles to the code page once the server lifts it', function () {
    config(['sanabel.two_factor_enforced' => false]);

    /*
     | Asked as «is it still sending them to the code page», not as «do they
     | reach this screen»: what the switch governs is the detour and nothing
     | else. A council account is turned away from the file list for its own
     | reasons, which are the permission table's business and not this
     | switch's, and a test that asked for a 200 would be testing that instead.
     */
    foreach (['admin', 'council', 'board_director', 'deputy_board_director'] as $role) {
        $response = $this->actingAs(userWithRole($role))
            ->get(BeneficiaryResource::getUrl('index'));

        expect($response->headers->get('Location'))
            ->not->toBe(TwoFactor::getUrl(panel: 'admin'));
    }
})->group('IAM-01');

it('shuts the gate again the moment the line is taken out', function () {
    $admin = userWithRole('admin');

    config(['sanabel.two_factor_enforced' => false]);
    $this->actingAs($admin)->get(BeneficiaryResource::getUrl('index'))->assertSuccessful();

    // No restart, no re-enrolment, no session to clear: the next request asks
    // the question again and gets the other answer.
    config(['sanabel.two_factor_enforced' => true]);
    $this->actingAs($admin)
        ->get(BeneficiaryResource::getUrl('index'))
        ->assertRedirect(TwoFactor::getUrl(panel: 'admin'));
})->group('IAM-01');

it('says so on every panel screen while it is lifted', function () {
    config(['sanabel.two_factor_enforced' => false]);

    $this->actingAs(userWithRole('admin'))
        ->get(BeneficiaryResource::getUrl('index'))
        ->assertSee(__('sanabel.two_factor.off_banner'))
        // And names the line that puts it back, so nobody has to ask us.
        ->assertSee('SANABEL_TWO_FACTOR');
})->group('IAM-01');

it('draws no band at all while the gate is doing its job', function () {
    $this->actingAs(enrolledAdmin())
        ->get(BeneficiaryResource::getUrl('index'))
        ->assertSuccessful()
        ->assertDontSee(__('sanabel.two_factor.off_banner'));
})->group('IAM-01');

it('unenrols nobody, so a secret already set still works afterwards', function () {
    $admin = enrolledAdmin();

    config(['sanabel.two_factor_enforced' => false]);
    $this->actingAs($admin)->get(BeneficiaryResource::getUrl('index'))->assertSuccessful();

    expect($admin->fresh()->hasConfirmedTwoFactor())->toBeTrue()
        // The roles that are meant to carry one are untouched: this lifts a
        // gate, it does not rewrite the policy behind it.
        ->and($admin->fresh()->requiresTwoFactor())->toBeTrue();
})->group('IAM-01');

it('leaves the enrolment page open while the gate is lifted', function () {
    config(['sanabel.two_factor_enforced' => false]);

    // So an administrator can still set a second factor up during the very
    // afternoon the gate is down, ready for when it goes back.
    Livewire\Livewire::actingAs(userWithRole('admin'))
        ->test(TwoFactor::class)
        ->assertSuccessful();
})->group('IAM-01');

it('cannot be lifted from inside the panel', function () {
    /*
     | The one thing that makes this switch safe: it is read from the server's
     | environment, so an account that got into the panel cannot turn off the
     | lock on the panel. It is not a row in `settings`, and it is not a field
     | a change request can carry.
     */
    Setting::put('two_factor_enforced', false);
    Setting::put('sanabel.two_factor_enforced', false);

    expect(RequireTwoFactor::enforced())->toBeTrue()
        ->and(ChangeRequest::CHANGEABLE_FIELDS)->not->toContain('two_factor_enforced');

    $this->actingAs(userWithRole('admin'))
        ->get(BeneficiaryResource::getUrl('index'))
        ->assertRedirect(TwoFactor::getUrl(panel: 'admin'));
})->group('IAM-01');

it('never lifts the gate for the panels of the association or the provider either', function () {
    // One switch, three panels: a half-lifted gate is the kind nobody can
    // reason about. Both of these panels carry the same middleware, and
    // neither of their roles is in the four — so the gate was never in their
    // way, and the band is still drawn for whoever walks past the screen.
    foreach (['admin', 'association', 'provider'] as $panel) {
        expect(Filament::getPanel($panel)->getAuthMiddleware())
            ->toContain(RequireTwoFactor::class);
    }
})->group('IAM-01');
