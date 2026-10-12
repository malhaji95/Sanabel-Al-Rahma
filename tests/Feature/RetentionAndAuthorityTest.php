<?php

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

it('opens the act to the two the association named, and to nobody else', function () {
    $case = fileLastTouched(6, $this->region);

    expect($this->service->isAuthorised($this->chairman, 'request_anonymise'))->toBeTrue()
        ->and($this->service->isAuthorised($this->director, 'request_anonymise'))->toBeTrue()
        ->and($this->service->isAuthorised($this->chairman, 'execute_anonymise'))->toBeTrue()
        ->and($this->service->isAuthorised($this->director, 'execute_anonymise'))->toBeTrue();

    foreach (['case_officer', 'area_supervisor', 'association', 'council', 'oversight_director', 'treasurer'] as $role) {
        $user = userWithRole($role, ['region_id' => $this->region->id]);

        expect($this->service->isAuthorised($user, 'request_anonymise'))->toBeFalse("{$role} may ask");
    }
})->group('FILE-04');

it('keeps the system administrator out of it, permissions or no permissions', function () {
    // admin holds every permission key in the matrix, which is why the check is
    // on the role as well: the association named two people, and this is not
    // one of them.
    $admin = userWithRole('admin', ['region_id' => $this->region->id]);
    $case = fileLastTouched(6, $this->region);

    expect($admin->can_('request_anonymise'))->toBeTrue()
        ->and($this->service->isAuthorised($admin, 'request_anonymise'))->toBeFalse();

    expect(fn () => $this->service->request($case, $admin, 'سند قانوني'))
        ->toThrow(RuntimeException::class);
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
