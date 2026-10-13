<?php

use App\Filament\Pages\ManageSettings;
use Filament\Facades\Filament;
use App\Livewire\FamilyPortal;
use App\Models\Setting;
use App\Services\AnonymiseService;
use Livewire\Livewire;

/*
 | T-71 — the association's answers of 12 October, in three parts.
 |
 | How long: five years, read off the Saudi commercial-register period for an
 | establishment, and they asked whether it could be moved later. It is a
 | setting, so it moves without a deploy, and these tests hold that.
 |
 | Who: the chairman of the board of trustees and the executive director, and
 | nobody else — not the system administrator, who holds every permission key
 | and whom they did not name.
 |
 | And the family's own account on their own page, so they can tell the desk
 | the number is wrong before a payment goes to it.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->service = app(AnonymiseService::class);
    $this->chairman = userWithRole('board_director', ['region_id' => $this->region->id]);
    $this->director = userWithRole('executive_director', ['region_id' => $this->region->id]);
});

/**
 * A file whose last activity was `$yearsAgo` years ago.
 *
 * Built today and then dated back, rather than built at that date: a region's
 * reference values come into force on a day, and an assessment computed before
 * that day has nothing approved to read, which is the engine refusing to
 * invent a number rather than anything to do with retention.
 */
function fileLastTouched(int $yearsAgo, $region)
{
    $then = now()->copy()->subYears($yearsAgo);
    $case = publishedCase($region);

    $case->forceFill([
        'created_at' => $then,
        'approved_at' => $then,
        'published_at' => $then,
        'last_assessment_at' => $then,
    ])->saveQuietly();

    return $case->fresh();
}

it('keeps the retention period in the settings, not in the code', function () {
    expect(config('sanabel.setting_defaults.anonymise_after_years'))->toBe(5)
        ->and(AnonymiseService::retentionYears())->toBe(5);

    // The association's own question: can it be moved later. It is a setting.
    Setting::put('anonymise_after_years', 7);
    Setting::forgetLoaded();

    expect(AnonymiseService::retentionYears())->toBe(7);
})->group('FILE-04');

it('runs the five years from the file\'s last activity, never from its opening', function () {
    $case = fileLastTouched(4, $this->region);

    // Four years since anything happened on it, so a year of the five is left.
    expect($this->service->retentionHasRunOut($case))->toBeFalse()
        ->and($this->service->retentionRunsOutAt($case)->year)->toBe(now()->year + 1);

    $old = fileLastTouched(6, $this->region);

    expect($this->service->retentionHasRunOut($old))->toBeTrue();
})->group('FILE-04');

it('does not push the clock out when somebody corrects a spelling', function () {
    $case = fileLastTouched(6, $this->region);

    expect($this->service->retentionHasRunOut($case))->toBeTrue();

    // An edit today. If the clock read updated_at, this family's retention
    // would start again, and a bulk edit would restart every family's.
    $case->forceFill(['family_name' => 'المصحَّح'])->save();

    expect($this->service->retentionHasRunOut($case->fresh()))->toBeTrue();
})->group('FILE-04');

it('refuses to destroy anything while the period is still running', function () {
    $case = fileLastTouched(2, $this->region);

    expect(fn () => $this->service->request($case, $this->chairman, 'طلب من جهة قضائية'))
        ->toThrow(RuntimeException::class);

    expect($case->fresh()->anonymise_requested_by)->toBeNull()
        ->and($case->fresh()->isAnonymised())->toBeFalse();
})->group('FILE-04');

it('names the date the period ends, so the answer is not just no', function () {
    $case = fileLastTouched(2, $this->region);

    try {
        $this->service->request($case, $this->chairman, 'سند قانوني');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain($this->service->retentionRunsOutAt($case)->toDateString());

        return;
    }

    $this->fail('the request was not refused');
})->group('FILE-04');

it('opens the act to the four the association named, and to nobody else', function () {
    // T-75 — widened on 13 October: both directors and both deputies, which
    // also means it no longer waits whenever one of two people is away.
    $deputyChairman = userWithRole('deputy_board_director');
    $deputyDirector = userWithRole('deputy_executive_director');

    foreach ([$this->chairman, $this->director, $deputyChairman, $deputyDirector] as $user) {
        expect($this->service->isAuthorised($user, 'request_anonymise'))->toBeTrue()
            ->and($this->service->isAuthorised($user, 'execute_anonymise'))->toBeTrue();
    }

    foreach (['case_officer', 'area_supervisor', 'association', 'council', 'oversight_director', 'treasurer'] as $role) {
        $user = userWithRole($role, ['region_id' => $this->region->id]);

        expect($this->service->isAuthorised($user, 'request_anonymise'))->toBeFalse("{$role} may ask");
    }
})->group('FILE-04');

it('keeps the system administrator out of it until the door is opened', function () {
    // admin holds every permission key in the matrix, which is why the check is
    // on the role as well. And on 13 October the association asked for that to
    // be a switch rather than a wall: «نحتاج تكون صلاحية مدير النظام اختياري
    // متى ما نحتاج نفتحها او نسكرها».
    $admin = userWithRole('admin', ['region_id' => $this->region->id]);
    $case = fileLastTouched(6, $this->region);

    expect(AnonymiseService::adminIsAllowed())->toBeFalse()
        ->and($admin->can_('request_anonymise'))->toBeTrue()
        ->and($this->service->isAuthorised($admin, 'request_anonymise'))->toBeFalse();

    expect(fn () => $this->service->request($case, $admin, 'سند قانوني'))
        ->toThrow(RuntimeException::class);
})->group('FILE-04');

it('lets the administrator in while the door is open, and out again when it is shut', function () {
    $admin = userWithRole('admin', ['region_id' => $this->region->id]);
    $case = fileLastTouched(6, $this->region);

    Setting::put(AnonymiseService::ADMIN_SWITCH, true);
    Setting::forgetLoaded();

    expect(AnonymiseService::adminIsAllowed())->toBeTrue()
        ->and($this->service->isAuthorised($admin, 'request_anonymise'))->toBeTrue();

    $this->service->request($case, $admin, 'سند قانوني بطلب من جهة قضائية');

    expect($case->fresh()->anonymise_requested_by)->toBe($admin->getKey());

    // Shut again: the request already raised stands, and the administrator
    // cannot carry one out any more.
    Setting::put(AnonymiseService::ADMIN_SWITCH, false);
    Setting::forgetLoaded();

    expect($this->service->isAuthorised($admin, 'execute_anonymise'))->toBeFalse();
})->group('FILE-04');

it('keeps the administrator from opening his own door', function () {
    // A door the administrator opens for himself is not a door. The switch
    // belongs to the accounts that hold the authority.
    $admin = userWithRole('admin');
    $chairman = userWithRole('board_director');

    $this->actingAs($admin);
    expect(ManageSettings::maySwitch(AnonymiseService::ADMIN_SWITCH))->toBeFalse();

    $this->actingAs($chairman);
    expect(ManageSettings::maySwitch(AnonymiseService::ADMIN_SWITCH))->toBeTrue();

    // Every other setting still follows edit_config, which the administrator holds.
    $this->actingAs($admin);
    expect(ManageSettings::maySwitch('anonymise_after_years'))->toBeTrue();
})->group('FILE-04');

it('keeps the switch shut by default, so nothing changes until somebody decides', function () {
    expect(config('sanabel.setting_defaults.'.AnonymiseService::ADMIN_SWITCH))->toBeFalse()
        ->and(AnonymiseService::adminIsAllowed())->toBeFalse();
})->group('FILE-04');

it('still takes two of them, now that only two may do it at all', function () {
    $case = fileLastTouched(6, $this->region);

    $this->service->request($case, $this->chairman, 'انتهت مدة الاحتفاظ وصدر التزام قانوني');

    expect(fn () => $this->service->execute($case->fresh(), $this->chairman))
        ->toThrow(RuntimeException::class);

    $this->service->execute($case->fresh(), $this->director);

    expect($case->fresh()->isAnonymised())->toBeTrue();
})->group('FILE-04');

it('shows the family the account the association holds for them', function () {
    $case = publishedCase($this->region, attributes: ['wallet_encrypted' => '0933123456']);
    $user = userWithRole('beneficiary');
    $case->forceFill(['user_id' => $user->getKey()])->save();

    Livewire::actingAs($user)->test(FamilyPortal::class)
        ->assertSee('0933123456')
        ->assertSee(__('sanabel.portal.my_wallet'));
});

it('tells a family with no account that the association pays for them', function () {
    $case = publishedCase($this->region, attributes: ['wallet_encrypted' => null]);
    $user = userWithRole('beneficiary');
    $case->forceFill(['user_id' => $user->getKey()])->save();

    Livewire::actingAs($user)->test(FamilyPortal::class)
        ->assertSee(__('sanabel.portal.no_wallet'));
});

it('shows one family their own account and never another family\'s', function () {
    $mine = publishedCase($this->region, attributes: ['wallet_encrypted' => '0933111111']);
    $theirs = publishedCase($this->region, attributes: ['wallet_encrypted' => '0944222222']);

    $user = userWithRole('beneficiary');
    $mine->forceFill(['user_id' => $user->getKey()])->save();

    Livewire::actingAs($user)->test(FamilyPortal::class)
        ->assertSee('0933111111')
        ->assertDontSee('0944222222')
        ->assertDontSee($theirs->file_number);
});

it('opens the settings screen to the account that holds the key to it', function () {
    /*
     | T-86 — found by the training capture, which opened every screen in each
     | guide as the account that guide is for, and was answered 403 here.
     |
     | Two things were wrong at once. The association said on 13 October that
     | «اظهار البيانات» is the executive director's to decide, and the screen
     | where it is decided was open to `edit_config` alone. And T-75 had put
     | the administrator's anonymisation door in the hands of the two directors
     | and their deputies — so the account holding that key could not reach the
     | lock.
     */
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    foreach (['executive_director', 'deputy_executive_director'] as $role) {
        $this->actingAs(userWithRole($role));

        expect(ManageSettings::canAccess())->toBeTrue($role.' cannot open the settings screen')
            // The door they were given, now reachable.
            ->and(ManageSettings::maySwitch(AnonymiseService::ADMIN_SWITCH))->toBeTrue()
            // And the display decisions the association put in their hands.
            ->and(ManageSettings::maySwitch('show_wallet_last4'))->toBeTrue()
            ->and(ManageSettings::maySwitch('funding_target_percent'))->toBeTrue();
    }

    // Unchanged: the board of trustees looks and does not touch.
    $this->actingAs(userWithRole('council'));

    expect(ManageSettings::canAccess())->toBeFalse();
})->group('FILE-04', 'IAM-01');
