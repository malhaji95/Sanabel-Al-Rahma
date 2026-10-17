<?php

use App\Models\Beneficiary;
use App\Models\DisbursementOrder;
use App\Models\Permission;
use App\Services\CaseService;
use App\Services\DisbursementService;
use App\Services\PermissionService;

/*
 | 16 October 2026. The association read the guides and corrected two things
 | about who may decide what.
 |
 | «نحتاج مدير النظام فقط صلاحيات تجهيز النظام فقط وليس له أي صلاحية اتخاذ
 | قرار او أنشاء ملف او امر صرف او اعتماد» — the technical account prepares
 | the system and decides nothing inside it.
 |
 | «نحتاج فقط التأكد بالاختبار أن نفس المستخدم لا يستطيع الجمع بين الخطوات
 | المتعارضة للعملية نفسها، حتى لو كان الدور يملك أكثر من صلاحية» — the money
 | path is four steps, and no one person takes two of them on the same
 | payment. The rule is about the person, not the role: a role that holds
 | every key still cannot be two of the four people.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
});

it('refuses the administrator every decision there is', function () {
    $admin = userWithRole('admin');
    $case = publishedCase($this->region);

    // Families.
    expect($admin->can('create', Beneficiary::class))->toBeFalse()
        ->and($admin->can('approve', $case))->toBeFalse()
        ->and($admin->can('suspend', $case))->toBeFalse()
        ->and($admin->can('endorse', $case))->toBeFalse()
        ->and($admin->can('verifyInField', $case))->toBeFalse();

    // Money.
    expect($admin->can('create', DisbursementOrder::class))->toBeFalse();

    foreach ([
        'approve_case', 'suspend_graduate', 'create_case', 'approve_change',
        'raise_disbursement_order', 'approve_disbursement', 'execute_disbursement',
        'reconcile_disbursement', 'confirm_disbursement', 'verify_payment',
        'approve_content', 'manage_cms', 'manage_campaigns', 'override_score',
        'merge_duplicates', 'handle_complaint', 'manage_distribution',
    ] as $key) {
        expect($admin->can_($key))->toBeFalse("the administrator still holds {$key}");
    }
})->group('AUTH-01', 'APP-01');

it('leaves the administrator the keys that prepare the system', function () {
    $admin = userWithRole('admin');

    // Accounts, roles and configuration: the job the role is for.
    expect($admin->can_('manage_users'))->toBeTrue()
        ->and($admin->can_('edit_config'))->toBeTrue()
        // And the reading it takes to see that a screen works.
        ->and($admin->can_('view_reports'))->toBeTrue()
        ->and($admin->can_('view_masked_case'))->toBeTrue();

    /*
     | The anonymisation keys stay, by the association's own decision of 12
     | October: a switch that is closed by default, and a second person to
     | carry out what the first asked for. This change does not quietly
     | reverse that one.
     */
    expect(PermissionService::SETUP_PERMISSIONS)
        ->toContain('request_anonymise')
        ->toContain('execute_anonymise');
})->group('AUTH-01');

it('holds the rule in code, not only in the rows the administrator can edit', function () {
    $admin = userWithRole('admin');

    // The one key the role keeps is the key that edits rows like these. So
    // granting itself the permission has to fail.
    $admin->role->permissions()->syncWithoutDetaching([
        Permission::where('key', 'approve_case')->value('id') => ['scope' => 'all'],
    ]);

    expect($admin->refresh()->can_('approve_case'))->toBeFalse();
})->group('AUTH-01', 'APP-01');

it('stops one person taking two steps of the same payment, whatever their role holds', function () {
    // The chairman holds every key in the matrix. That is the point of the
    // test: the four steps are four people, not four permissions.
    $everything = userWithRole('board_director');
    $treasurer = userWithRole('treasurer');

    $officer = userWithRole('case_officer');
    $case = publishedCase($this->region);
    $service = app(DisbursementService::class);

    // Confirmed, then gathered into an order the chairman raised.
    $line = $service->confirm($case, 10_000, $officer);
    $order = $service->gather([$line], $everything);
    $service->submit($order->refresh());

    // Raise then approve: refused before today, and still refused.
    expect(fn () => $service->approve($order->refresh(), $everything))
        ->toThrow(RuntimeException::class);

    $service->approve($order->refresh(), $treasurer);

    // Approve then execute: the hand that authorised it does not move it.
    expect(fn () => $service->execute($line->refresh(), $treasurer, 'TRX-1'))
        ->toThrow(RuntimeException::class, __('sanabel.disbursement.no_same_hand'));

    $executed = $service->execute($line->refresh(), $everything, 'TRX-1');

    // Execute then reconcile.
    expect(fn () => $service->reconcile($executed->refresh(), $everything))
        ->toThrow(RuntimeException::class, __('sanabel.disbursement.no_same_hand'));

    // And the hand that authorised it does not certify it landed either.
    expect(fn () => $service->reconcile($executed->refresh(), $treasurer))
        ->toThrow(RuntimeException::class, __('sanabel.disbursement.no_same_hand'));

    $accountant = userWithRole('finance');

    expect($service->reconcile($executed->refresh(), $accountant)->status)->toBe('reconciled');
})->group('APP-01', 'AUTH-01');

it('keeps the four steps four separate keys, so no role is given two by accident', function () {
    // A role may legitimately hold more than one — the treasurer raises the
    // order and moves the money. What it may never do is take two steps on
    // the same payment, which is the test above. This one holds the shape.
    foreach ([
        'raise_disbursement_order', 'approve_disbursement',
        'execute_disbursement', 'reconcile_disbursement',
    ] as $key) {
        expect(PermissionService::WRITE_PERMISSIONS)->toContain($key);
    }
})->group('APP-01');

it('lets the file still reach approval without the administrator in the chain', function () {
    // Taking a key away is only right if the work still gets done. The chain
    // the association set out runs delegate → supervisor → executive
    // director, and none of those three is the technical account.
    $delegate = userWithRole('delegate', ['region_id' => $this->region->id]);
    $supervisor = userWithRole('area_supervisor', ['region_id' => $this->region->id]);
    $director = userWithRole('executive_director');

    $case = familyOf($this->region, attributes: [
        'created_by' => $delegate->id,
        'status' => 'pending_visit',
    ]);

    $service = app(CaseService::class);
    $case = $service->verifyInField($case, $delegate);
    $case = $service->endorse($case, $supervisor);

    expect($service->approve($case, $director)->status)->toBe('approved');
})->group('AUTH-01');
