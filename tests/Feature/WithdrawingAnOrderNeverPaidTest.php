<?php

use App\Filament\Resources\DisbursementOrderResource\Pages\ListDisbursementOrders;
use App\Models\DisbursementOrder;
use App\Services\DisbursementService;
use Filament\Facades\Filament;

/*
 | T-227 — «يُلغى أمر الصرف غير المنفّذ من صاحب الصلاحية المحددة. يُسجّل سبب
 | الإلغاء واسم المسؤول وتاريخه في سجل العمليات. يُنشأ أمر صرف جديد بالبيانات
 | المصححة. يمر الأمر الجديد بكامل مراحل المراجعة والاعتماد من البداية»,
 | 1 November.
 |
 | `cancelled` had been a declared order status since the table was written,
 | with a colour of its own in the list, and nothing anywhere set it. So an
 | approved order carrying a wrong amount was stuck: frozen against editing,
 | which is right, and with no way out, which is not. The association's
 | answer is not an edit — the approved figure is never quietly corrected.
 | The order is withdrawn whole, by name, with a reason, and its lines go
 | back to the queue to be gathered into a corrected order that is approved
 | from the beginning.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->officer = userWithRole('case_officer', ['region_id' => $this->region->id]);
    $this->treasurer = userWithRole('treasurer');
    $this->director = userWithRole('executive_director');
    $this->service = app(DisbursementService::class);
});

/** An order approved and waiting on the treasurer, holding `$count` lines. */
function orderAwaitingPayment(int $count = 2): DisbursementOrder
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

it('withdraws an approved order nothing was paid out of', function () {
    $order = orderAwaitingPayment();

    $cancelled = $this->service->cancelOrder($order, $this->director, 'المبلغ مكرر لعائلتين.');

    expect($cancelled->status)->toBe('cancelled')
        ->and($cancelled->cancelled_by)->toBe($this->director->id)
        ->and($cancelled->cancelled_at)->not->toBeNull()
        ->and($cancelled->cancel_reason_ar)->toBe('المبلغ مكرر لعائلتين.');
});

it('sends every line back to the queue, so a corrected order may hold them', function () {
    $order = orderAwaitingPayment(3);
    $lines = $order->disbursements;

    $this->service->cancelOrder($order, $this->director, 'خطأ بالمبالغ.');

    foreach ($lines as $line) {
        $line->refresh();

        // Where they stood before the treasurer gathered them: owed,
        // unapproved, and free to join the next order.
        expect($line->status)->toBe('confirmed')
            ->and($line->disbursement_order_id)->toBeNull()
            ->and($line->approved_by)->toBeNull()
            ->and($line->approved_at)->toBeNull();
    }
});

it('lets the corrected order be raised and approved from the beginning', function () {
    $order = orderAwaitingPayment(2);
    $lines = $order->disbursements->all();

    $this->service->cancelOrder($order, $this->director, 'المبلغ غلط.');

    // Nothing special is needed: the ordinary path, from the first step.
    $again = $this->service->gather(
        collect($lines)->map->refresh()->all(), $this->treasurer
    );
    $this->service->submit($again);
    $this->service->approve($again->refresh(), $this->director);

    expect($again->refresh()->status)->toBe('approved')
        ->and($again->id)->not->toBe($order->id)
        ->and($again->disbursements()->count())->toBe(2);
});

it('refuses a withdrawal with no reason written', function () {
    $order = orderAwaitingPayment();

    expect(fn () => $this->service->cancelOrder($order, $this->director, '   '))
        ->toThrow(RuntimeException::class);

    expect($order->refresh()->status)->toBe('approved');
});

it('refuses to withdraw an order money has already left', function () {
    $order = orderAwaitingPayment(2);
    $line = $order->disbursements->first();

    $this->service->execute($line, $this->treasurer, 'TRF-9001', proof: transferProof());

    // After the money moves the correction is a reversal, which is a
    // different act with different evidence (rule 5).
    expect(fn () => $this->service->cancelOrder($order->refresh(), $this->director, 'تصحيح.'))
        ->toThrow(RuntimeException::class);

    expect($order->refresh()->status)->not->toBe('cancelled')
        ->and($order->cancelled_at)->toBeNull();
});

it('refuses an order already withdrawn, so no second reason overwrites the first', function () {
    $order = orderAwaitingPayment();

    $this->service->cancelOrder($order, $this->director, 'السبب الأول.');

    expect(fn () => $this->service->cancelOrder($order->refresh(), $this->director, 'سبب آخر.'))
        ->toThrow(RuntimeException::class);

    expect($order->refresh()->cancel_reason_ar)->toBe('السبب الأول.');
});

it('offers the button on the screen to the hand that approves, and takes the reason there', function () {
    passTwoFactor();
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $order = orderAwaitingPayment();

    Livewire\Livewire::actingAs($this->director)
        ->test(ListDisbursementOrders::class)
        ->assertTableActionVisible('cancelOrder', $order)
        ->callTableAction('cancelOrder', $order, ['reason' => 'المبلغ مكرر.'])
        ->assertHasNoTableActionErrors();

    expect($order->refresh()->status)->toBe('cancelled')
        ->and($order->cancel_reason_ar)->toBe('المبلغ مكرر.');
});

it('offers it to nobody who does not approve orders', function () {
    passTwoFactor();
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $order = orderAwaitingPayment();

    // The treasurer raised it; undoing an approval is not theirs to do.
    Livewire\Livewire::actingAs($this->treasurer)
        ->test(ListDisbursementOrders::class)
        ->assertTableActionHidden('cancelOrder', $order);
});

it('hides it once the order is settled, there being nothing left to withdraw', function () {
    passTwoFactor();
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $order = orderAwaitingPayment(1);
    $order->forceFill(['status' => 'settled'])->save();

    Livewire\Livewire::actingAs($this->director)
        ->test(ListDisbursementOrders::class)
        ->assertTableActionHidden('cancelOrder', $order);
});
