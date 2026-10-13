<?php

use App\Http\Resources\MaskedCaseResource;
use App\Models\Campaign;
use App\Models\DonationAllocation;
use App\Models\Donor;
use App\Models\Setting;
use App\Services\CoverageService;
use App\Services\DonationService;
use App\Services\RankingService;
use Illuminate\Support\Carbon;

/*
 | T-83 — the completed month, as the association asked for it on 13 October:
 | «الحاله مكتمله تنزل تحت ويكون مكتوب عليها مكتمله وتفتح بعدها الحاله للشهر
 | الجديد مع كتابة تغطية، شهر كذا».
 |
 | Three things follow from that sentence, and each is pinned below. A family
 | whose month is answered stays on the donor's screen instead of vanishing,
 | but underneath every family still waiting. It carries the word «مكتملة».
 | And the month every figure answers is written on the card, because a
 | donation answers one month and no other — which is also what makes the
 | reopening legible rather than mysterious.
 |
 | What did not change: the plain funding list. docs/03-rules.md §3 takes a
 | covered family out of it, and the distribution engine reads it to decide who
 | is handed money. A covered family appearing there would be a payment nobody
 | asked for.
 */

beforeEach(function () {
    // The tenth of the month: the next month opens a configurable number of
    // days before this one ends, so a test standing on a real date would pass
    // or fail depending on when it was run.
    $this->travelTo(Carbon::now()->startOfMonth()->addDays(10));

    seedCore();
    $this->region = regionWithRates();
    $this->coverage = app(CoverageService::class);
    $this->admin = userWithRole('admin');
});

/** Verified money answering one month, which is the only money that counts. */
function fundMonth($case, int $amount, $admin, ?Carbon $month = null): void
{
    static $ref = 0;

    $donation = app(DonationService::class)->record([
        'donor_id' => Donor::factory()->create()->id,
        'amount' => $amount,
        'transaction_ref' => 'TRX-MONTH-'.(++$ref),
    ]);

    DonationAllocation::create([
        'donation_id' => $donation->id,
        'beneficiary_id' => $case->id,
        'amount' => $amount,
        'currency' => config('sanabel.currency'),
        'coverage_month' => ($month ?? now())->copy()->startOfMonth(),
    ]);

    app(DonationService::class)->verify($donation, $admin->id);
}

/** Only this month is fundable, whatever day of the month the suite runs on. */
function onlyThisMonthOpen(): void
{
    Setting::put('next_month_opens_days_before', 0);
}

/** This month and the next, both. */
function nextMonthOpen(): void
{
    Setting::put('next_month_opens_days_before', 31);
}

it('keeps a covered family on the donor list, at the bottom, marked complete', function () {
    onlyThisMonthOpen();

    $short = publishedCase($this->region);
    $covered = publishedCase($this->region);
    fundMonth($covered, $this->coverage->needAmount($covered), $this->admin);

    $list = app(RankingService::class)->fundingList('monthly', withCovered: true);

    expect($list->pluck('beneficiary.id')->all())->toBe([$short->id, $covered->id])
        ->and($list->last()['covered'])->toBeTrue()
        ->and($list->first()['covered'])->toBeFalse();

    $card = (new MaskedCaseResource($covered, $list->last()['confirmed'], $list->last()['month']))->resolve();

    // The word they asked to see written on it.
    expect($card['is_covered'])->toBeTrue()
        ->and($card['priority_label'])->toBe('مكتملة')
        ->and($card['coverage_percent'])->toBe(100);
});

it('still leaves a covered family out of the list the distribution engine reads', function () {
    onlyThisMonthOpen();

    $short = publishedCase($this->region);
    $covered = publishedCase($this->region);
    fundMonth($covered, $this->coverage->needAmount($covered), $this->admin);

    // docs/03-rules.md §3, untouched: remaining = 0 removes the case.
    expect(app(RankingService::class)->fundingList('monthly')->pluck('beneficiary.id')->all())
        ->toBe([$short->id]);
});

it('writes the month every figure on the card answers', function () {
    $case = publishedCase($this->region);
    $month = now()->startOfMonth();

    $card = (new MaskedCaseResource($case))->resolve();

    expect($card['coverage_month'])->toBe($month->format('Y-m'))
        ->and($card['coverage_month_label'])->toBe($month->translatedFormat('F Y'))
        ->and($card['is_covered'])->toBeFalse();
});

it('opens a covered family for the new month once that month opens', function () {
    nextMonthOpen();

    $case = publishedCase($this->region);
    $need = $this->coverage->needAmount($case);
    fundMonth($case, $need, $this->admin);

    $next = now()->startOfMonth()->addMonth();

    expect($this->coverage->monthIsCovered($case))->toBeTrue()
        ->and($this->coverage->fundableMonth($case)->format('Y-m'))->toBe($next->format('Y-m'));

    $row = app(RankingService::class)
        ->fundingList('monthly', withCovered: true)
        ->firstWhere('beneficiary.id', $case->id);

    expect($row['covered'])->toBeFalse()
        ->and($row['month']->format('Y-m'))->toBe($next->format('Y-m'));

    $card = (new MaskedCaseResource($case, $row['confirmed'], $row['month']))->resolve();

    expect($card['coverage_month'])->toBe($next->format('Y-m'))
        ->and($card['coverage_month_label'])->toBe($next->translatedFormat('F Y'))
        ->and($card['is_covered'])->toBeFalse()
        // Nothing has arrived for the new month, so the whole need is open.
        ->and($card['remaining_amount'])->toBe($need);
});

it('never lets a family reopened for next month climb over one still short this month', function () {
    nextMonthOpen();

    $short = publishedCase($this->region);

    $reopened = publishedCase($this->region);
    fundMonth($reopened, $this->coverage->needAmount($reopened), $this->admin);

    $done = publishedCase($this->region);
    $doneNeed = $this->coverage->needAmount($done);
    fundMonth($done, $doneNeed, $this->admin);
    fundMonth($done, $doneNeed, $this->admin, now()->startOfMonth()->addMonth());

    $list = app(RankingService::class)->fundingList('monthly', withCovered: true);

    expect($list->pluck('beneficiary.id')->all())->toBe([$short->id, $reopened->id, $done->id]);
});

it('never shows a published family that has no need recorded, covered or otherwise', function () {
    onlyThisMonthOpen();

    // No assessment, so no need: not a funding opportunity, and certainly not
    // one to label «مكتملة».
    $noNeed = familyOf($this->region, attributes: [
        'status' => 'published',
        'approved_at' => now()->subDay(),
        'published_at' => now()->subDay(),
    ]);

    expect($this->coverage->needAmount($noNeed))->toBe(0)
        ->and(app(RankingService::class)->fundingList('monthly', withCovered: true)
            ->pluck('beneficiary.id')->all())->not->toContain($noNeed->id)
        ->and(app(RankingService::class)->fundingList('monthly')
            ->pluck('beneficiary.id')->all())->not->toContain($noNeed->id);
});

it('leaves a shared link alive for the new month instead of closing it', function () {
    nextMonthOpen();

    $case = publishedCase($this->region);
    fundMonth($case, $this->coverage->needAmount($case), $this->admin);

    $next = now()->startOfMonth()->addMonth();

    $this->get(route('opportunity', $case->file_number))
        ->assertOk()
        ->assertSee(__('sanabel.funding.covers_month', ['month' => $next->translatedFormat('F Y')]))
        ->assertDontSee(__('sanabel.share.closed_title'));
});

it('closes a shared link when there is no open month left to fund', function () {
    onlyThisMonthOpen();

    $case = publishedCase($this->region);
    fundMonth($case, $this->coverage->needAmount($case), $this->admin);

    $this->get(route('opportunity', $case->file_number))
        ->assertOk()
        ->assertSee(__('sanabel.share.closed_title'));
});

it('shows the family monthly funding on its own campaign, and hides it on the switch', function () {
    $case = publishedCase($this->region);
    Campaign::factory()->create([
        'beneficiary_id' => $case->id,
        'is_published' => true,
        'status' => 'active',
        'surplus_policy_text_ar' => 'يوجه الفائض لأسرة أخرى ضمن نفس المنطقة.',
    ]);

    $label = __('sanabel.funding.show_monthly_funding_on_campaign');

    expect(config('sanabel.setting_defaults.show_monthly_funding_on_campaign'))->toBeTrue();

    $this->get(route('campaigns.public'))->assertOk()->assertSee($label);

    Setting::put('show_monthly_funding_on_campaign', false);

    $this->get(route('campaigns.public'))->assertOk()->assertDontSee($label);
});

it('says on the donor list which month the box is asking for', function () {
    onlyThisMonthOpen();

    $case = publishedCase($this->region);
    $donor = userWithRole('donor');
    Donor::factory()->create(['user_id' => $donor->id]);

    $this->actingAs($donor)
        ->get(route('cases.browse'))
        ->assertOk()
        ->assertSee(__('sanabel.funding.covers_month', [
            'month' => now()->startOfMonth()->translatedFormat('F Y'),
        ]));
});
