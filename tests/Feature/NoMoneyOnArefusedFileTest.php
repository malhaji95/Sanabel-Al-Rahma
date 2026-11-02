<?php

use App\Models\Beneficiary;
use App\Models\Disbursement;
use App\Services\DisbursementService;

/*
 | T-229 — money is not committed on a file the association did not decide
 | to help.
 |
 | Nobody reported this. It came out of reading the demonstration: a payment
 | could be confirmed on a file in any status at all, a rejected one
 | included. The two gates on `confirm()` were the amount and the ceiling,
 | and the ceiling is computed from the household's monthly need — which
 | survives a rejection, so it was positive and the line was written, drew
 | from the family's earmarked donations, and went on to be gathered into an
 | order and approved.
 |
 | The gate is in the service and not on the screen, because the screen is
 | one way in of several: a bulk confirmation, a seeder, a command, a screen
 | nobody has written yet.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->officer = userWithRole('case_officer', ['region_id' => $this->region->id]);
    $this->service = app(DisbursementService::class);
});

/** A published file moved to this status, keeping its assessment. */
function fileInStatus($region, string $status): Beneficiary
{
    $case = publishedCase($region);
    $case->forceFill(['status' => $status])->save();

    return $case->refresh();
}

/** What a month on this file would be worth, read before the status moved. */
function monthlyFor(Beneficiary $case): int
{
    return app(DisbursementService::class)->remainingForPeriod($case, now());
}

it('refuses a payment on a file the association refused', function () {
    $case = publishedCase($this->region);
    $amount = monthlyFor($case);
    $case->forceFill(['status' => 'rejected'])->save();

    expect(fn () => $this->service->confirm($case->refresh(), $amount, $this->officer))
        ->toThrow(RuntimeException::class);

    // And nothing was written: no line, and nothing drawn from the family's
    // earmarked donations (T-213).
    expect(Disbursement::where('beneficiary_id', $case->id)->count())->toBe(0);
});

it('refuses one on a file nobody has decided yet', function () {
    foreach (['draft', 'pending_visit', 'returned', 'verified', 'pending_approval'] as $status) {
        $case = publishedCase($this->region);
        $amount = monthlyFor($case);
        $case->forceFill(['status' => $status])->save();

        expect(fn () => $this->service->confirm($case->refresh(), $amount, $this->officer))
            ->toThrow(RuntimeException::class);
    }
});

it('refuses one on a file whose support was stopped', function () {
    $case = publishedCase($this->region);
    $amount = monthlyFor($case);
    $case->forceFill(['status' => 'suspended'])->save();

    expect(fn () => $this->service->confirm($case->refresh(), $amount, $this->officer))
        ->toThrow(RuntimeException::class);
});

it('refuses one on a file that graduated out of need', function () {
    $case = publishedCase($this->region);
    $amount = monthlyFor($case);
    $case->forceFill(['status' => 'graduated'])->save();

    expect(fn () => $this->service->confirm($case->refresh(), $amount, $this->officer))
        ->toThrow(RuntimeException::class);
});

it('refuses one on a duplicate folded into another file, which would pay twice', function () {
    $case = publishedCase($this->region);
    $amount = monthlyFor($case);
    $case->forceFill(['status' => 'merged'])->save();

    expect(fn () => $this->service->confirm($case->refresh(), $amount, $this->officer))
        ->toThrow(RuntimeException::class);
});

it('pays a published file, which is what it was always for', function () {
    $case = publishedCase($this->region);

    $line = $this->service->confirm($case, monthlyFor($case), $this->officer);

    expect($line->status)->toBe('confirmed');
});

it('pays an approved file that is not published to donors', function () {
    $case = publishedCase($this->region);
    $amount = monthlyFor($case);
    $case->forceFill(['status' => 'approved'])->save();

    // Not every file the association helps is offered to a donor; the
    // general fund pays without publication.
    $line = $this->service->confirm($case->refresh(), $amount, $this->officer);

    expect($line->status)->toBe('confirmed');
});

it('keeps paying a household whose reassessment has come due', function () {
    $case = publishedCase($this->region);
    $amount = monthlyFor($case);
    $case->forceFill(['status' => 'needs_reassessment'])->save();

    // Deliberate: the association is already helping this household, and
    // stopping its aid because a form fell due is a decision nobody asked
    // for.
    $line = $this->service->confirm($case->refresh(), $amount, $this->officer);

    expect($line->status)->toBe('confirmed');
});

it('says which status refused it, so the desk is not left guessing', function () {
    $case = publishedCase($this->region);
    $amount = monthlyFor($case);
    $case->forceFill(['status' => 'rejected'])->save();

    try {
        $this->service->confirm($case->refresh(), $amount, $this->officer);
        $this->fail('a payment was confirmed on a rejected file');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe(__('sanabel.disbursement.file_may_not_receive', [
            'status' => __('sanabel.status.rejected'),
        ]));
    }
});

it('leaves a payment already confirmed alone when the file is refused afterwards', function () {
    $case = publishedCase($this->region);
    $line = $this->service->confirm($case, monthlyFor($case), $this->officer);

    $case->forceFill(['status' => 'rejected'])->save();

    /*
     | The gate is on committing money, not on money already committed. A
     | line written while the file stood published is part of the record and
     | is withdrawn by the people and the steps that withdraw payments —
     | rule 5 for a payment already made, and T-227 for an order not yet
     | paid — never by a status changing underneath it.
     */
    expect($line->refresh()->status)->toBe('confirmed');
});
