<?php

use App\Filament\Resources\BeneficiaryResource;
use App\Filament\Resources\DisbursementOrderResource;
use App\Filament\Resources\DisbursementResource;
use App\Models\AppNotification;
use App\Models\Beneficiary;
use App\Models\Disbursement;
use App\Models\DisbursementOrder;
use App\Models\Setting;
use App\Services\CoverageService;
use App\Services\DisbursementService;
use Filament\Facades\Filament;

/*
 | T-141 — the association's notes 4, 8 and 10 of 28 October:
 |
 |   · «المبلغ المتبقي من احتياج الاسرة هذا الشهر … هل هذا الأحتياج الأساسي
 |     المتبقي أم مبلغ التمويل الي هو مثلا 30 بالمئة؟»
 |   · «اول شي حالة امر صرف ما عم تفتح, لازم تفتح لانه ممكن المدير التنفيذي
 |     يحتاج يطلع على الحالة قبل اعتماد»
 |   · «بعد ما امر الصرف راح للمدير التنفيذي وتم اعتماده رجع عند امين الصندوق
 |     ما عطاني اي حالة محددة ولازم يقدر يفتح الملف عند المالية بعد الاعتماد
 |     حتى يتم التحويل وتظهر بحالة تم التحويل»
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    passTwoFactor();
});

/** One confirmed line, gathered into an order and submitted for approval. */
function orderAwaitingApproval(Beneficiary $family): DisbursementOrder
{
    $service = app(DisbursementService::class);

    $line = $service->confirm($family, 10_000, userWithRole('case_officer'));
    $order = $service->gather([$line], userWithRole('treasurer'));

    return $service->submit($order);
}

it('tells the approver that an order is waiting, and the treasurer that it passed', function () {
    $family = publishedCase($this->region);
    $director = userWithRole('executive_director');
    $treasurer = userWithRole('treasurer');

    $order = orderAwaitingApproval($family);

    expect(AppNotification::where('recipient_id', $director->id)
        ->where('template_key', 'disbursement_order_submitted')->exists())->toBeTrue();

    app(DisbursementService::class)->approve($order, $director);

    $told = AppNotification::where('recipient_id', $treasurer->id)
        ->where('template_key', 'disbursement_order_approved')
        ->first();

    expect($told)->not->toBeNull()
        // The reference and nothing else: no family, no amount, no name.
        ->and($told->payload_json)->toBe(['ref' => $order->reference_no]);
})->group('GOV-01');

it('tells the accountant when a transfer has left', function () {
    $family = publishedCase($this->region);
    $accountant = userWithRole('finance');

    $order = orderAwaitingApproval($family);
    app(DisbursementService::class)->approve($order, userWithRole('executive_director'));

    $line = $order->disbursements()->firstOrFail();
    app(DisbursementService::class)->execute($line, userWithRole('treasurer'), 'TRX-OUT-1');

    expect(AppNotification::where('recipient_id', $accountant->id)
        ->where('template_key', 'disbursement_executed')->exists())->toBeTrue()
        // And the line says it has gone, in the association's own words.
        ->and($line->refresh()->status)->toBe('executed')
        ->and(__('sanabel.disbursement.statuses.executed'))->toContain('منفذ');
});

it('opens the family file from the money row, on the screen an approver can open', function () {
    $family = publishedCase($this->region);

    Disbursement::create([
        'beneficiary_id' => $family->id, 'amount' => 10_000,
        'currency' => 'SYP', 'period' => now()->startOfMonth(), 'status' => 'confirmed',
    ]);

    $html = Livewire\Livewire::actingAs(userWithRole('executive_director'))
        ->test(DisbursementResource\Pages\ListDisbursements::class)
        ->assertSuccessful()
        ->html();

    // The read-only screen: the edit screen opens for `edit_draft`, which an
    // approver does not hold.
    expect($html)->toContain(BeneficiaryResource::getUrl('view', ['record' => $family->id]))
        ->and($html)->not->toContain(BeneficiaryResource::getUrl('edit', ['record' => $family->id]));
})->group('AUTH-01');

it('opens it from inside the order too, where the approval is given', function () {
    $family = publishedCase($this->region);
    $order = orderAwaitingApproval($family);

    $html = Livewire\Livewire::actingAs(userWithRole('executive_director'))
        ->test(DisbursementOrderResource\RelationManagers\DisbursementsRelationManager::class, [
            'ownerRecord' => $order,
            'pageClass' => DisbursementOrderResource\Pages\EditDisbursementOrder::class,
        ])
        ->assertSuccessful()
        ->html();

    expect($html)->toContain(BeneficiaryResource::getUrl('view', ['record' => $family->id]));
})->group('AUTH-01');

it('names both figures on the new-order screen, which was the question', function () {
    $family = publishedCase($this->region);

    $page = Livewire\Livewire::actingAs(userWithRole('case_officer'))
        ->test(DisbursementResource\Pages\CreateDisbursement::class)
        ->fillForm(['beneficiary_id' => $family->id]);

    $html = $page->html();

    expect($html)->toContain(__('sanabel.disbursement.remaining_this_month'))
        ->toContain(__('sanabel.disbursement.target_this_month'))
        // And each says what it is, so neither is read as the other.
        ->toContain('سقف ما يُصرف')
        ->toContain('رقم جمعٍ لا سقف صرف');
});

it('keeps the ceiling on the real need, not on the funding stage', function () {
    // The answer to the question: the stage is what donors are asked for; the
    // ceiling is what the family may be paid.
    Setting::put('funding_target_percent', 30);

    $family = publishedCase($this->region);

    $need = app(CoverageService::class)->needAmount($family);
    $stage = app(CoverageService::class)->targetAmount($family);
    $ceiling = app(DisbursementService::class)->remainingForPeriod($family, now()->startOfMonth());

    expect($stage)->toBeLessThan($need)
        ->and($ceiling)->toBe($need)
        ->and($ceiling)->toBeGreaterThan($stage);
});
