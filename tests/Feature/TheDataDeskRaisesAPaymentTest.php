<?php

use App\Filament\Resources\DonationResource;
use App\Models\AppNotification;
use App\Models\Donation;
use App\Models\Donor;
use App\Services\DisbursementService;
use Filament\Facades\Filament;

/*
 | T-143 — «صفحة مسؤول البيانات: لما تجي التبرعات لعنده يقدر يعمل طلب صرف
 | ويطلع لامين الصندوق ويتم بعدين يطلع عند المدير التنفيذي ويعمل موافقة
 | ويرجع لامين الصندوق ويعمل التحويل وتجي رسالة تأكيد التحويل. ومسؤول
 | البيانات يظهر عنده فقط المقيدة بينما التبرعات العامة لصندوق سنابل يوصل
 | لامين الصندوق», 28 October.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    passTwoFactor();
});

it('lets the data desk raise what a family is owed', function () {
    $family = publishedCase($this->region);
    $supervisor = userWithRole('data_supervisor');

    expect($supervisor->can_('confirm_disbursement'))->toBeTrue();

    $line = app(DisbursementService::class)->confirm($family, 10_000, $supervisor);

    expect($line->status)->toBe('confirmed')
        ->and($line->confirmed_by)->toBe($supervisor->id);
})->group('AUTH-01');

it('shows that desk the money that names files, and not the association fund', function () {
    $donor = Donor::factory()->create();

    $earmarked = Donation::factory()->create([
        'donor_id' => $donor->id, 'designation' => 'earmarked',
        'transaction_ref' => 'TRX-EARMARKED', 'status' => 'verified',
    ]);

    $general = Donation::factory()->create([
        'donor_id' => $donor->id, 'designation' => 'general',
        'transaction_ref' => 'TRX-GENERAL', 'status' => 'verified',
    ]);

    $this->actingAs(userWithRole('data_supervisor'));

    $visible = DonationResource::getEloquentQuery()->pluck('transaction_ref');

    expect($visible)->toContain('TRX-EARMARKED')
        ->and($visible)->not->toContain('TRX-GENERAL');

    // The treasurer reads both: the general fund is theirs to allocate.
    $this->actingAs(userWithRole('treasurer'));

    expect(DonationResource::getEloquentQuery()->pluck('transaction_ref'))
        ->toContain('TRX-EARMARKED')
        ->toContain('TRX-GENERAL');
})->group('AUTH-02');

it('opens the donations screen to the desk that pays out of them', function () {
    Livewire\Livewire::actingAs(userWithRole('data_supervisor'))
        ->test(DonationResource\Pages\ListDonations::class)
        ->assertSuccessful();
});

it('walks one payment the whole way, and tells the desk it has gone', function () {
    $family = publishedCase($this->region);

    $supervisor = userWithRole('data_supervisor');
    $treasurer = userWithRole('treasurer');
    $director = userWithRole('executive_director');

    $service = app(DisbursementService::class);

    // The data desk raises it.
    $line = $service->confirm($family, 10_000, $supervisor);

    // The treasurer gathers and submits it.
    $order = $service->submit($service->gather([$line], $treasurer));

    expect($order->status)->toBe('pending_approval')
        ->and(AppNotification::where('recipient_id', $director->id)
            ->where('template_key', 'disbursement_order_submitted')->exists())->toBeTrue();

    // The director approves it, and the treasurer is told.
    $service->approve($order, $director);

    expect(AppNotification::where('recipient_id', $treasurer->id)
        ->where('template_key', 'disbursement_order_approved')->exists())->toBeTrue();

    // The treasurer transfers it, and the desk that asked hears the end of it.
    $service->execute($line->refresh(), $treasurer, 'TRX-PAID-1');

    expect($line->refresh()->status)->toBe('executed')
        ->and(AppNotification::where('recipient_id', $supervisor->id)
            ->where('template_key', 'disbursement_executed')->exists())->toBeTrue();
})->group('PAY-03');

it('keeps the four steps in four pairs of hands', function () {
    // Nobody who raises a line also approves it or moves its money.
    $supervisor = userWithRole('data_supervisor');

    expect($supervisor->can_('confirm_disbursement'))->toBeTrue()
        ->and($supervisor->can_('raise_disbursement_order'))->toBeFalse()
        ->and($supervisor->can_('approve_disbursement'))->toBeFalse()
        ->and($supervisor->can_('execute_disbursement'))->toBeFalse()
        ->and($supervisor->can_('verify_payment'))->toBeFalse();
})->group('AUTH-01');
