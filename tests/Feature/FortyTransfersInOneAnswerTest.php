<?php

use App\Filament\Resources\DisbursementOrderResource\Pages\EditDisbursementOrder;
use App\Filament\Resources\DisbursementOrderResource\RelationManagers\DisbursementsRelationManager;
use App\Models\AppNotification;
use App\Models\Disbursement;
use App\Models\DisbursementOrder;
use App\Services\DisbursementService;
use Filament\Facades\Filament;

/*
 | T-216 — «زر التحويل الجماعي يحفظ نتيجة كل حوالة لحالها, حتى لو نجح بعضها
 | وفشل بعضها», 1 November.
 |
 | The line was already the unit the system records against — its own
 | reference, its own state, its own failure reason — so the property they
 | asked for was never in doubt. What did not exist was the button: an order
 | of forty families was forty modals, each asking for one number.
 |
 | So: one question, forty answers, each saved on its own. A row left blank
 | is not a failure, it is a line not moved yet and it stays approved. A row
 | the treasurer may not move — because they approved it themselves — is
 | refused and named, and the rows already written above it stand.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->officer = userWithRole('case_officer', ['region_id' => $this->region->id]);
    $this->treasurer = userWithRole('treasurer');
    $this->director = userWithRole('executive_director');
    passTwoFactor();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

/** An approved order holding `$count` lines, each for its own family. */
function approvedOrderOf(int $count): DisbursementOrder
{
    $service = app(DisbursementService::class);

    $lines = collect(range(1, $count))->map(function () use ($service) {
        $case = publishedCase(test()->region);

        return $service->confirm($case, $service->remainingForPeriod($case, now()), test()->officer);
    });

    $order = $service->gather($lines->all(), test()->treasurer);
    $service->submit($order);
    $service->approve($order->refresh(), test()->director);

    return $order->refresh();
}

/** The bulk action, answered row by row. */
function payInBulk(DisbursementOrder $order, array $answers): void
{
    Livewire\Livewire::actingAs(test()->treasurer)
        ->test(DisbursementsRelationManager::class, [
            'ownerRecord' => $order,
            'pageClass' => EditDisbursementOrder::class,
        ])
        ->callTableBulkAction('executeMany', $order->disbursements->pluck('id')->all(), $answers);
}

it('pays several lines in one answer, each with its own reference', function () {
    $order = approvedOrderOf(3);
    [$a, $b, $c] = $order->disbursements->all();

    payInBulk($order, [
        'outcome_'.$a->id => 'executed', 'ref_'.$a->id => 'TRF-1',
        'outcome_'.$b->id => 'executed', 'ref_'.$b->id => 'TRF-2',
        'outcome_'.$c->id => 'executed', 'ref_'.$c->id => 'TRF-3',
    ]);

    expect($a->refresh()->transfer_ref)->toBe('TRF-1')
        ->and($b->refresh()->transfer_ref)->toBe('TRF-2')
        ->and($c->refresh()->transfer_ref)->toBe('TRF-3')
        ->and([$a->status, $b->status, $c->status])->toBe(['executed', 'executed', 'executed']);
});

it('keeps what succeeded when one of them did not arrive', function () {
    $order = approvedOrderOf(3);
    [$a, $b, $c] = $order->disbursements->all();

    payInBulk($order, [
        'outcome_'.$a->id => 'executed', 'ref_'.$a->id => 'TRF-10',
        'outcome_'.$b->id => 'failed', 'why_'.$b->id => 'رقم الحساب مغلق.',
        'outcome_'.$c->id => 'executed', 'ref_'.$c->id => 'TRF-12',
    ]);

    expect($a->refresh()->status)->toBe('executed')
        ->and($b->refresh()->status)->toBe('failed')
        ->and($b->failure_reason_ar)->toBe('رقم الحساب مغلق.')
        // The one after the failure is paid too: the row is the unit, not
        // the batch, so nothing stops at the first bad line.
        ->and($c->refresh()->status)->toBe('executed')
        ->and($c->transfer_ref)->toBe('TRF-12');
});

it('leaves a line alone when the treasurer has not moved it yet', function () {
    $order = approvedOrderOf(2);
    [$a, $b] = $order->disbursements->all();

    payInBulk($order, [
        'outcome_'.$a->id => 'executed', 'ref_'.$a->id => 'TRF-20',
        'outcome_'.$b->id => 'skip',
    ]);

    expect($a->refresh()->status)->toBe('executed')
        // Not failed. Still owed, still approved, still waiting.
        ->and($b->refresh()->status)->toBe('approved')
        ->and($b->transfer_ref)->toBeNull();
});

it('refuses the hand that approved a line, and pays the rest regardless', function () {
    $order = approvedOrderOf(2);
    [$a, $b] = $order->disbursements->all();

    // T-105 — whoever authorised the payment does not also move it. The
    // first line is made to read as though this treasurer approved it, so
    // the service refuses that one row and only that one.
    $a->forceFill(['approved_by' => $this->treasurer->id])->save();

    payInBulk($order, [
        'outcome_'.$a->id => 'executed', 'ref_'.$a->id => 'TRF-30',
        'outcome_'.$b->id => 'executed', 'ref_'.$b->id => 'TRF-31',
    ]);

    // The refusal is collected, not thrown: it stops its own row and the
    // row after it is paid, which is the whole point of the batch.
    expect($a->refresh()->status)->toBe('approved')
        ->and($a->transfer_ref)->toBeNull()
        ->and($b->refresh()->status)->toBe('executed')
        ->and($b->transfer_ref)->toBe('TRF-31');
});

it('tells each household only about the line that was actually transferred', function () {
    $order = approvedOrderOf(2);
    [$a, $b] = $order->disbursements->all();

    foreach ([$a, $b] as $line) {
        $line->beneficiary->forceFill(['user_id' => userWithRole('beneficiary')->id])->save();
    }

    payInBulk($order, [
        'outcome_'.$a->id => 'executed', 'ref_'.$a->id => 'TRF-40',
        'outcome_'.$b->id => 'failed', 'why_'.$b->id => 'الحوالة ارتدّت.',
    ]);

    $told = fn (Disbursement $line) => AppNotification::where(
        'recipient_id', $line->beneficiary->refresh()->user_id
    )->where('template_key', 'disbursement_to_confirm')->count();

    expect($told($a))->toBeGreaterThan(0)
        ->and($told($b))->toBe(0);
});

it('offers the button to nobody who may not move money', function () {
    $order = approvedOrderOf(1);

    Livewire\Livewire::actingAs($this->officer)
        ->test(DisbursementsRelationManager::class, [
            'ownerRecord' => $order,
            'pageClass' => EditDisbursementOrder::class,
        ])
        ->assertTableBulkActionHidden('executeMany');
});
