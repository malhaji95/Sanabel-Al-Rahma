<?php

use App\Models\ConflictWaiver;
use App\Services\ConflictOfInterest;
use Illuminate\Support\Carbon;

/*
 | T-318 — phase two, item 4: «الاستثناء المؤقّت».
 |
 | T-305 built the bar and the key: a disclosed relationship stops the hand
 | that would verify, endorse or approve, and an independent account with its
 | own permission may lift that stop with a written reason.
 |
 | What it could not do is lift it for a week. A waiver granted once held for
 | ever, so an exception made for one urgent file quietly became a standing
 | permission over that family.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->case = familyOf($this->region, adults: 1, children: 0);

    // The hand that is barred, and the independent hand that may lift it.
    $this->officer = userWithRole('case_officer', ['region_id' => $this->region->id]);
    $this->director = userWithRole('executive_director');

    app(ConflictOfInterest::class)->declare(
        $this->case,
        $this->officer,
        true,
        'other_kin',
        'ابن خالتي.',
    );
});

it('bars the hand that declared a relationship, until somebody lifts it', function () {
    $conflict = app(ConflictOfInterest::class);

    expect($conflict->isBarred($this->case, $this->officer))->toBeTrue();

    $conflict->waive($this->case, $this->officer, $this->director, 'الملف عاجل ولا بديل اليوم.');

    expect($conflict->isBarred($this->case, $this->officer))->toBeFalse();
});

it('lets the lift be given a term, and drops it when the term runs out', function () {
    $conflict = app(ConflictOfInterest::class);

    $conflict->waive(
        $this->case,
        $this->officer,
        $this->director,
        'الملف عاجل, ولأسبوع واحد.',
        Carbon::today()->addDays(7),
    );

    expect($conflict->isBarred($this->case, $this->officer))->toBeFalse();

    // A week on, the bar is back without anybody doing anything.
    $this->travel(8)->days();

    expect($conflict->isBarred($this->case, $this->officer))->toBeTrue();

    // And the row is still there, saying who lifted it, why, and until when:
    // that is what the oversight committee reads it for.
    $waiver = ConflictWaiver::where('beneficiary_id', $this->case->id)
        ->where('user_id', $this->officer->id)
        ->first();

    expect($waiver)->not->toBeNull()
        ->and($waiver->deleted_at)->toBeNull()
        ->and($waiver->granted_by)->toBe($this->director->id)
        ->and($waiver->reason_ar)->toBe('الملف عاجل, ولأسبوع واحد.')
        ->and($waiver->hasRunOut())->toBeTrue();
});

it('counts the last day of the term as inside it', function () {
    $conflict = app(ConflictOfInterest::class);

    $conflict->waive($this->case, $this->officer, $this->director, 'لليوم فقط.', Carbon::today());

    // Still lifted at the end of that same day.
    $this->travelTo(Carbon::today()->setTime(23, 30));

    expect($conflict->isBarred($this->case, $this->officer))->toBeFalse();

    // And barred the next morning.
    $this->travelTo(Carbon::today()->addDay()->setTime(8, 0));

    expect($conflict->isBarred($this->case, $this->officer))->toBeTrue();
});

it('leaves a lift with no term exactly as it was', function () {
    /*
     | Every waiver granted before today has no end date. Giving them one now
     | would change a decision somebody made under different terms, so an
     | empty term means what it always meant.
     */
    $conflict = app(ConflictOfInterest::class);

    $conflict->waive($this->case, $this->officer, $this->director, 'قرار دائم.');

    $waiver = ConflictWaiver::where('user_id', $this->officer->id)->first();

    expect($waiver->expires_on)->toBeNull()
        ->and($waiver->hasRunOut())->toBeFalse();

    $this->travel(5)->years();

    expect($conflict->isBarred($this->case, $this->officer))->toBeFalse();
});

it('refuses a term that has already run out', function () {
    expect(fn () => app(ConflictOfInterest::class)->waive(
        $this->case,
        $this->officer,
        $this->director,
        'بتاريخ ماض.',
        Carbon::today()->subDay(),
    ))->toThrow(RuntimeException::class, __('sanabel.conflict.waiver_expiry_past'));

    // Nothing was written, so the bar still stands.
    expect(app(ConflictOfInterest::class)->isBarred($this->case, $this->officer))->toBeTrue()
        ->and(ConflictWaiver::count())->toBe(0);
});

it('still refuses a lift by the barred hand itself, and one with no reason', function () {
    /*
     | T-305's three conditions are untouched: the term is a fourth thing, not
     | a way round the first three.
     */
    $conflict = app(ConflictOfInterest::class);

    // The director holds the key, so a self-lift reaches the independence
    // rule rather than stopping at the permission one.
    $conflict->declare($this->case, $this->director, true, 'other_kin', 'وأنا أيضاً.');

    expect(fn () => $conflict->waive($this->case, $this->director, $this->director, 'أرفعه عن نفسي.', Carbon::today()->addWeek()))
        ->toThrow(RuntimeException::class, __('sanabel.conflict.waiver_not_independent'));

    // And the officer, who does not hold the key at all, is refused earlier.
    expect(fn () => $conflict->waive($this->case, $this->officer, $this->officer, 'أرفعه عن نفسي.', Carbon::today()->addWeek()))
        ->toThrow(RuntimeException::class, __('sanabel.conflict.waiver_not_permitted'));

    expect(fn () => $conflict->waive($this->case, $this->officer, $this->director, '   ', Carbon::today()->addWeek()))
        ->toThrow(RuntimeException::class, __('sanabel.conflict.waiver_reason_required'));

    expect(ConflictWaiver::count())->toBe(0);
});

it('stops the guarded act again once the term has run out', function () {
    $conflict = app(ConflictOfInterest::class);

    $conflict->waive($this->case, $this->officer, $this->director, 'ليومين.', Carbon::today()->addDays(2));

    // While it holds, the act goes through.
    $conflict->guard($this->case, $this->officer, 'approve');

    $this->travel(3)->days();

    expect(fn () => $conflict->guard($this->case, $this->officer, 'approve'))
        ->toThrow(RuntimeException::class);
});
