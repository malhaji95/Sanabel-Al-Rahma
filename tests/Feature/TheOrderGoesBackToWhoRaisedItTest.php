<?php

use App\Filament\Resources\DisbursementOrderResource\Pages\EditDisbursementOrder;
use App\Filament\Resources\DisbursementOrderResource\RelationManagers\DisbursementsRelationManager;
use App\Models\AppNotification;
use App\Models\Disbursement;
use App\Models\DisbursementOrder;
use App\Services\DisbursementService;
use Filament\Facades\Filament;

/*
 | T-237 — «ويرجع الأمر للموظف المسؤول عن تجهيزه حتى يستبعد السطر غير
 | المؤهل، وبعدها يعيد تقديم الأمر للاعتماد», 3 November.
 |
 | T-238 — «بما أن التحويل البنكي عندنا يدوي، لازم يكون فحص أهلية الأسرة قبل
 | تنفيذ التحويل البنكي الفعلي، وليس فقط عند تسجيل «تم التحويل» بعد خروج
 | المال», the same message.
 |
 | T-234 refused the approval and left the order where it was: in the
 | approver's queue, which is the one desk that cannot take a line out of
 | it, while the desk that can was never told. And it refused the transfer
 | at the moment of recording, which in a manual system is after the
 | treasurer has already been to the bank.
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
function standingLine(): Disbursement
{
    $case = publishedCase(test()->region);
    $service = app(DisbursementService::class);

    return $service->confirm($case, $service->remainingForPeriod($case, now()), test()->officer);
}

/** An order submitted and waiting on the approver. */
function submittedOrderOver(array $lines): DisbursementOrder
{
    $service = app(DisbursementService::class);
    $order = $service->gather($lines, test()->treasurer);
    $service->submit($order);

    return $order->refresh();
}

it('sends the order back to draft when approval finds a family no longer standing', function () {
    $good = standingLine();
    $gone = standingLine();
    $order = submittedOrderOver([$good, $gone]);

    $gone->beneficiary->forceFill(['status' => 'suspended'])->save();

    expect(fn () => $this->service->approve($order->refresh(), $this->director))
        ->toThrow(RuntimeException::class);

    // Back on the desk that can act on it, not stuck on the one that cannot.
    expect($order->refresh()->status)->toBe('draft')
        ->and($order->submitted_at)->toBeNull();
});

it('tells the hand that raised it, by file number', function () {
    $good = standingLine();
    $gone = standingLine();
    $order = submittedOrderOver([$good, $gone]);
    $gone->beneficiary->forceFill(['status' => 'rejected'])->save();

    try {
        $this->service->approve($order->refresh(), $this->director);
    } catch (RuntimeException) {
    }

    $told = AppNotification::where('recipient_id', $this->treasurer->id)
        ->where('template_key', 'disbursement_order_returned')
        ->get();

    // One row per channel, which is how every notice in this system is
    // written; what matters here is that it went once, to the right hand.
    expect($told->pluck('channel')->unique()->values()->all())->toBe(['in_app', 'email'])
        ->and($told->pluck('recipient_id')->unique()->all())->toBe([$this->treasurer->id])
        ->and($told->first()->payload_json['ref'])->toBe($order->refresh()->reference_no)
        ->and($told->first()->payload_json['file_number'])->toContain($gone->beneficiary->file_number);
});

it('lets the treasurer take the line out and submit the order again', function () {
    $good = standingLine();
    $gone = standingLine();
    $order = submittedOrderOver([$good, $gone]);
    $gone->beneficiary->forceFill(['status' => 'suspended'])->save();

    try {
        $this->service->approve($order->refresh(), $this->director);
    } catch (RuntimeException) {
    }

    // The whole point of sending it back: both of these now work.
    $this->service->remove($gone->refresh());
    $this->service->submit($order->refresh());
    $approved = $this->service->approve($order->refresh(), $this->director);

    expect($approved->status)->toBe('approved')
        ->and($approved->disbursements()->count())->toBe(1)
        ->and($good->refresh()->status)->toBe('approved');
});

it('leaves the standing families in the order untouched while it waits', function () {
    $good = standingLine();
    $gone = standingLine();
    $order = submittedOrderOver([$good, $gone]);
    $gone->beneficiary->forceFill(['status' => 'suspended'])->save();

    try {
        $this->service->approve($order->refresh(), $this->director);
    } catch (RuntimeException) {
    }

    // Their amounts and their standing in the order do not move.
    expect($good->refresh()->status)->toBe('in_order')
        ->and($good->disbursement_order_id)->toBe($order->id);
});

it('approves normally when every family in the order still stands', function () {
    $order = submittedOrderOver([standingLine(), standingLine()]);

    expect($this->service->approve($order->refresh(), $this->director)->status)->toBe('approved');
});

it('answers whether a line may be transferred, before anybody goes to the bank', function () {
    $line = standingLine();

    expect($this->service->mayBeTransferred($line))->toBeTrue();

    $line->beneficiary->forceFill(['status' => 'suspended'])->save();

    // The same question `execute()` asks, asked before the money moves
    // rather than when it is being recorded.
    expect($this->service->mayBeTransferred($line->refresh()))->toBeFalse();
});

it('marks the line on the screen so the treasurer sees it before the transfer', function () {
    passTwoFactor();
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $standing = standingLine();
    $stopped = standingLine();
    $order = submittedOrderOver([$standing, $stopped]);
    $this->service->approve($order->refresh(), $this->director);

    $stopped->beneficiary->forceFill(['status' => 'suspended'])->save();

    Livewire\Livewire::actingAs($this->treasurer)
        ->test(DisbursementsRelationManager::class, [
            'ownerRecord' => $order->refresh(),
            'pageClass' => EditDisbursementOrder::class,
        ])
        ->assertTableColumnStateSet('eligible_now', __('sanabel.disbursement.not_eligible_now'), $stopped)
        // Said only where it is bad news: a row that may be paid says
        // nothing, so the warning keeps its weight.
        ->assertTableColumnStateSet('eligible_now', null, $standing);
});
