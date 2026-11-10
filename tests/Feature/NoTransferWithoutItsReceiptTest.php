<?php

use App\Models\Disbursement;
use App\Models\Media;
use App\Services\DisbursementService;
use App\Services\MediaService;

/*
 | T-226 — «ما منقبل تحويل بدون مرجع قابل للمطابقة وإثبات تنفيذ»,
 | 1 November.
 |
 | Half of that was already true: the reference has been required since the
 | transfer screen was built. The other half was not required at all — it
 | was not even asked for. `execute()` took a proof id, no screen ever
 | supplied one, and the single transfer form offered one box: the
 | reference. So every transfer this system has recorded carries no
 | evidence, and the accountant reconciling against the bank statement has
 | a number and nothing behind it.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->officer = userWithRole('case_officer', ['region_id' => $this->region->id]);
    $this->treasurer = userWithRole('treasurer');
    $this->director = userWithRole('executive_director');
    $this->service = app(DisbursementService::class);
});

/** A line approved and waiting on the treasurer. */
function lineAwaitingTransfer($region, $officer, $treasurer, $director): Disbursement
{
    $case = publishedCase($region);
    $service = app(DisbursementService::class);

    $line = $service->confirm($case, $service->remainingForPeriod($case, now()), $officer);
    $order = $service->gather([$line], $treasurer);
    $service->submit($order);
    $service->approve($order->refresh(), $director);

    return $line->refresh();
}

it('refuses a transfer that carries no receipt', function () {
    $line = lineAwaitingTransfer($this->region, $this->officer, $this->treasurer, $this->director);

    expect(fn () => $this->service->execute($line, $this->treasurer, 'TRF-1'))
        ->toThrow(RuntimeException::class);

    // And nothing moved: the line is still owed, still approved, still the
    // treasurer's to pay once they have the slip in hand.
    expect($line->refresh()->status)->toBe('approved')
        ->and($line->transfer_ref)->toBeNull()
        ->and($line->executed_at)->toBeNull();
});

it('records the transfer once the receipt is attached', function () {
    $line = lineAwaitingTransfer($this->region, $this->officer, $this->treasurer, $this->director);

    $this->service->execute($line, $this->treasurer, 'TRF-2', proof: transferProof());

    $line->refresh();

    expect($line->status)->toBe('executed')
        ->and($line->transfer_ref)->toBe('TRF-2')
        ->and($line->proof_media_id)->not->toBeNull();
});

it('keeps the receipt against the line it belongs to, and names who uploaded it', function () {
    $line = lineAwaitingTransfer($this->region, $this->officer, $this->treasurer, $this->director);

    $this->service->execute($line, $this->treasurer, 'TRF-3', proof: transferProof());

    $media = Media::find($line->refresh()->proof_media_id);

    expect($media)->not->toBeNull()
        ->and($media->owner_type)->toBe(Disbursement::class)
        ->and($media->owner_id)->toBe($line->id)
        ->and($media->kind)->toBe('transfer_proof')
        ->and($media->uploaded_by)->toBe($this->treasurer->id);
});

it('accepts a receipt that is already stored, for a caller that holds one', function () {
    $line = lineAwaitingTransfer($this->region, $this->officer, $this->treasurer, $this->director);

    $stored = app(MediaService::class)
        ->store(transferProof(), $line, 'transfer_proof', $this->treasurer);

    $this->service->execute($line, $this->treasurer, 'TRF-4', proofMediaId: $stored->id);

    expect($line->refresh()->proof_media_id)->toBe($stored->id);
});

it('still refuses the reference on its own, which was never the hard half', function () {
    $line = lineAwaitingTransfer($this->region, $this->officer, $this->treasurer, $this->director);

    // An empty reference was already refused by the form; the point here is
    // that a reference does not buy its way past the receipt.
    expect(fn () => $this->service->execute($line, $this->treasurer, 'TRF-5', proofMediaId: null))
        ->toThrow(RuntimeException::class);
});

it('says why it refused, so the treasurer knows what to go and fetch', function () {
    $line = lineAwaitingTransfer($this->region, $this->officer, $this->treasurer, $this->director);

    try {
        $this->service->execute($line, $this->treasurer, 'TRF-6');
        $this->fail('the transfer was recorded without a receipt');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe(__('sanabel.disbursement.proof_required'));
    }
});
