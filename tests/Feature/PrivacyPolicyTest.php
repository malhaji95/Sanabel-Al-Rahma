<?php

use App\Models\Page;
use Database\Seeders\PrivacyPolicySeeder;
use Illuminate\Support\Facades\File;

/*
 | T-81 — the policy the association adopted on 13 October 2026.
 |
 | The consent columns have existed since T-69, and since T-74 a file cannot
 | leave draft without them. But there was no policy to show: a family was
 | agreeing to a document nobody could produce, and «الاطلاع على سياسة
 | الخصوصية» meant looking at nothing.
 |
 | What these hold: the text is published and reachable, the version in force
 | has a text to go with it, and a family's consent is stamped with that version
 | rather than with whatever a clerk typed.
 */

beforeEach(function () {
    seedCore();
    $this->seed(PrivacyPolicySeeder::class);
});

it('publishes the policy where a family and a donor can read it', function () {
    $page = Page::where('slug', PrivacyPolicySeeder::SLUG)->first();

    expect($page)->not->toBeNull()
        ->and($page->is_published)->toBeTrue()
        ->and($page->title_ar)->toBe(__('sanabel.privacy.page_title'));

    $this->get('/'.PrivacyPolicySeeder::SLUG)
        ->assertOk()
        // A clause from each end of it, so a truncated file shows up.
        ->assertSee('نطاق السياسة', false)
        ->assertSee('التواصل معنا', false);
})->group('FILE-04');

it('keeps the version in force and its text together', function () {
    $version = config('sanabel.privacy_policy.version');

    expect($version)->not->toBeEmpty();

    $path = database_path('data/policies/privacy-'.$version.'.md');

    expect(File::exists($path))->toBeTrue(
        "version {$version} is in force with no text at {$path}"
    );

    // And what was published is that text, not something edited since.
    expect(Page::where('slug', PrivacyPolicySeeder::SLUG)->value('body_ar'))
        ->toBe(File::get($path));
})->group('FILE-04');

it('carries the clauses that bear on what the system does', function () {
    $text = File::get(database_path('data/policies/privacy-'.config('sanabel.privacy_policy.version').'.md'));

    // Rule 2, in their own words: a beneficiary's data is not disclosed to a
    // donor in a way that identifies them.
    expect($text)->toContain('ولا تكشف بياناتهم للمتبرعين')
        // The retention clause the five-year setting rests on.
        ->and($text)->toContain('الاحتفاظ بالبيانات')
        // And the clause that makes hosting outside Syria permissible, which
        // the hosting proposal depends on.
        ->and($text)->toContain('نقل البيانات خارج الدولة');
})->group('FILE-04');

it('stamps the consent with the version in force, rather than with a typed one', function () {
    $source = File::get(app_path('Filament/Resources/BeneficiaryResource.php'));

    expect($source)->toContain("->dehydrateStateUsing(fn () => config('sanabel.privacy_policy.version'))")
        ->and($source)->toContain('->disabled()');
})->group('FILE-04');

it('offers the policy on the screen where the consent is taken', function () {
    // «الاطلاع» has to be possible before it can be recorded.
    $source = File::get(app_path('Filament/Resources/BeneficiaryResource.php'));

    expect($source)->toContain("url('/privacy')")
        ->and($source)->toContain('privacy_policy_link');
});

it('records on a family which version they agreed to', function () {
    $region = regionWithRates();
    $this->actingAs(userWithRole('case_officer'));

    $case = familyOf($region, attributes: [
        'privacy_policy_seen' => true,
        'privacy_consent' => true,
        'privacy_consent_on' => now()->toDateString(),
        'privacy_policy_version' => config('sanabel.privacy_policy.version'),
    ]);

    expect($case->fresh()->privacy_policy_version)->toBe(config('sanabel.privacy_policy.version'))
        ->and($case->fresh()->privacyConsentIsSound())->toBeTrue();
});

it('republishes the same text without making a second page', function () {
    $before = Page::where('slug', PrivacyPolicySeeder::SLUG)->count();

    $this->seed(PrivacyPolicySeeder::class);

    expect(Page::where('slug', PrivacyPolicySeeder::SLUG)->count())->toBe($before);
});

it('says plainly that the legal review has not happened yet', function () {
    // The association adopted it as it stands so the phase is not held up, and
    // said their adviser may revise it. The system should not pretend that
    // review has happened.
    expect(config('sanabel.privacy_policy.pending_legal_review'))->toBeTrue()
        ->and(config('sanabel.privacy_policy.adopted_on'))->toBe('2026-10-13');
});
