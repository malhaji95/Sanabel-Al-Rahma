<?php

use App\Filament\Resources\DisbursementOrderResource\Pages\EditDisbursementOrder;
use App\Filament\Resources\DisbursementOrderResource\RelationManagers\DisbursementsRelationManager;
use App\Models\AuditLog;
use App\Models\Disbursement;
use App\Services\DisbursementService;
use Filament\Facades\Filament;

/*
 | T-234 — «لو كانت الأسرة معتمدة وقت إنشاء أمر الصرف، وبعدها تم إيقافها أو
 | رفضها قبل تنفيذ التحويل، هل النظام يمنع التحويل تلقائيا؟ وكذلك بالصرف
 | الجماعي، هل يتم فحص أهلية كل أسرة بشكل مستقل قبل التنفيذ؟», 3 November.
 |
 | The answer was no, and the question was right. T-229 put the gate on
 | creating the payment and nowhere else, so a household's standing was read
 | once — at the moment the line was written — and never again. Joining an
 | order, the approval and the transfer all asked about the line's status,
 | never about the family behind it. A family approved on Sunday and
 | suspended on Monday was still paid on Tuesday.
 |
 | It is asked again now at all three steps, and these are the tests they
 | asked for with their results.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->officer = userWithRole('case_officer', ['region_id' => $this->region->id]);
    $this->treasurer = userWithRole('treasurer');
    $this->director = userWithRole('executive_director');
    $this->service = app(DisbursementService::class);
});

/** A line confirmed while its family stood published. */
function lineOnAstandingFamily(): Disbursement
{
    $case = publishedCase(test()->region);
    $service = app(DisbursementService::class);

    return $service->confirm($case, $service->remainingForPeriod($case, now()), test()->officer);
}

/** An order approved while every family in it stood. */
function approvedOrderOver(array $lines)
{
    $service = app(DisbursementService::class);
    $order = $service->gather($lines, test()->treasurer);
    $service->submit($order);
    $service->approve($order->refresh(), test()->director);

    return $order->refresh();
}

it('refuses the transfer when the family was suspended after the order was approved', function () {
    $line = lineOnAstandingFamily();
    $order = approvedOrderOver([$line]);

    // Everything was in order when it was approved. Then the association
    // stopped this family's support.
    $line->beneficiary->forceFill(['status' => 'suspended'])->save();

    expect(fn () => $this->service->execute($line->refresh(), $this->treasurer, 'TRF-1', proof: transferProof()))
        ->toThrow(RuntimeException::class);

    expect($line->refresh()->status)->toBe('approved')
        ->and($line->transfer_ref)->toBeNull()
        ->and($line->executed_at)->toBeNull()
        ->and($order->refresh()->status)->toBe('approved');
});

it('refuses it when the family was rejected after the order was approved', function () {
    $line = lineOnAstandingFamily();
    approvedOrderOver([$line]);

    $line->beneficiary->forceFill(['status' => 'rejected'])->save();

    expect(fn () => $this->service->execute($line->refresh(), $this->treasurer, 'TRF-2', proof: transferProof()))
        ->toThrow(RuntimeException::class);

    expect($line->refresh()->status)->toBe('approved');
});

it('refuses to approve an order that holds a family no longer standing, and names the file', function () {
    $good = lineOnAstandingFamily();
    $gone = lineOnAstandingFamily();

    $order = $this->service->gather([$good, $gone], $this->treasurer);
    $this->service->submit($order);

    $gone->beneficiary->forceFill(['status' => 'suspended'])->save();

    try {
        $this->service->approve($order->refresh(), $this->director);
        $this->fail('an order holding a suspended family was approved');
    } catch (RuntimeException $e) {
        // Named, so the treasurer knows which line to take out.
        expect($e->getMessage())->toContain($gone->beneficiary->file_number);
    }

    /*
     | Nothing was approved, and the order is whole.
     |
     | T-237 — it waits on its preparer now, not on the approver. This line
     | asserted «pending_approval» when it was written, which was right
     | then: the association decided on 3 November that a refused order
     | goes back to the desk that can take the line out, and the approver's
     | desk is the one desk that cannot.
     */
    expect($order->refresh()->status)->toBe('draft')
        ->and($good->refresh()->status)->toBe('in_order');
});

it('refuses to put a line into an order once its family has been stopped', function () {
    $line = lineOnAstandingFamily();
    $line->beneficiary->forceFill(['status' => 'rejected'])->save();

    expect(fn () => $this->service->gather([$line->refresh()], $this->treasurer))
        ->toThrow(RuntimeException::class);
});

it('checks each family on its own in the bulk screen, and pays the rest', function () {
    passTwoFactor();
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $standing = lineOnAstandingFamily();
    $stopped = lineOnAstandingFamily();
    $order = approvedOrderOver([$standing, $stopped]);

    // One family suspended after the approval. The other is untouched.
    $stopped->beneficiary->forceFill(['status' => 'suspended'])->save();

    Livewire\Livewire::actingAs($this->treasurer)
        ->test(DisbursementsRelationManager::class, [
            'ownerRecord' => $order,
            'pageClass' => EditDisbursementOrder::class,
        ])
        ->callTableBulkAction('executeMany', $order->disbursements->pluck('id')->all(), [
            'outcome_'.$standing->id => 'executed',
            'ref_'.$standing->id => 'TRF-10',
            'proof_'.$standing->id => transferProof(),
            'outcome_'.$stopped->id => 'executed',
            'ref_'.$stopped->id => 'TRF-11',
            'proof_'.$stopped->id => transferProof(),
        ]);

    // Independently: the standing family is paid, the stopped one is not,
    // and the batch did not fall over on the bad row.
    expect($standing->refresh()->status)->toBe('executed')
        ->and($standing->transfer_ref)->toBe('TRF-10')
        ->and($stopped->refresh()->status)->toBe('approved')
        ->and($stopped->transfer_ref)->toBeNull();
});

it('writes the refused transfer to the oversight log with the status that stopped it', function () {
    $line = lineOnAstandingFamily();
    approvedOrderOver([$line]);
    $line->beneficiary->forceFill(['status' => 'rejected'])->save();

    try {
        $this->service->execute($line->refresh(), $this->treasurer, 'TRF-3', proof: transferProof());
    } catch (RuntimeException) {
    }

    $entry = AuditLog::where('action', 'disbursement_refused')->get()->last();

    expect($entry->after_json['reason'])->toBe('file_no_longer_eligible')
        ->and($entry->after_json['file_status'])->toBe('rejected')
        ->and($entry->after_json['file_number'])->toBe($line->beneficiary->file_number)
        ->and($entry->actor_id)->toBe($this->treasurer->id);
});

it('still pays a family that stood at every one of the three steps', function () {
    $line = lineOnAstandingFamily();
    approvedOrderOver([$line]);

    $paid = $this->service->execute($line->refresh(), $this->treasurer, 'TRF-4', proof: transferProof());

    expect($paid->status)->toBe('executed')
        ->and($paid->transfer_ref)->toBe('TRF-4');
});

it('keeps paying a family whose reassessment fell due between the steps', function () {
    $line = lineOnAstandingFamily();
    approvedOrderOver([$line]);

    // Deliberate, and the same judgement as T-229: the association is
    // already helping this household, and a form falling due is not a
    // decision to stop its aid.
    $line->beneficiary->forceFill(['status' => 'needs_reassessment'])->save();

    expect($this->service->execute($line->refresh(), $this->treasurer, 'TRF-5', proof: transferProof())->status)
        ->toBe('executed');
});
