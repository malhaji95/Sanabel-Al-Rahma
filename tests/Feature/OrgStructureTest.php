<?php

use App\Models\Role;
use App\Services\PermissionService;

/*
 | The administrative structure the association set out on 5 and 6 October.
 | Roles are rows, so what follows asserts the rows — who may approve, who may
 | only look, and who never touches money.
 */

beforeEach(function () {
    seedCore();
});

it('seeds every role the association named, each with an Arabic name', function () {
    $expected = [
        'board_director', 'executive_director', 'deputy_executive_director',
        'deputy_area_supervisor', 'treasurer', 'oversight_director',
        'data_officer', 'data_supervisor', 'data_manager',
    ];

    foreach ($expected as $key) {
        $role = Role::where('key', $key)->first();

        expect($role)->not->toBeNull("role {$key} is missing")
            ->and($role->name_ar)->not->toBeEmpty()
            ->and($role->permissions)->not->toBeEmpty();
    }

    // The board of trustees took the place of the board of directors; there is
    // only one board, and it reads.
    expect(Role::where('key', 'council')->first()->name_ar)->toBe('مجلس الأمناء');
});

it('gives the trustees chairman everything, and the technical account no family file', function () {
    $chair = userWithRole('board_director');

    foreach (PermissionService::WRITE_PERMISSIONS as $key) {
        expect($chair->can_($key))->toBeTrue("chairman should hold {$key}");
    }

    // Unchanged by the new structure: the system administrator stays technical.
    expect(userWithRole('admin')->can('create', App\Models\Beneficiary::class))->toBeFalse()
        ->and($chair->can('create', App\Models\Beneficiary::class))->toBeTrue();
});

it('lets the board and the oversight committee look at everything and write nothing', function () {
    foreach (['council', 'oversight_director'] as $key) {
        $user = userWithRole($key);

        expect($user->isReadOnly())->toBeTrue();

        foreach (PermissionService::WRITE_PERMISSIONS as $write) {
            expect($user->can_($write))->toBeFalse("{$key} must not hold {$write}");
        }

        expect($user->can_('view_full_case'))->toBeTrue()
            ->and($user->can_('view_reports'))->toBeTrue();
    }
});

it('separates the approval from the entry and from the money', function () {
    $director = userWithRole('executive_director');

    // Approves.
    expect($director->can_('approve_case'))->toBeTrue()
        ->and($director->can_('approve_change'))->toBeTrue()
        // Does not enter, and does not move money.
        ->and($director->can_('create_case'))->toBeFalse()
        ->and($director->can_('edit_draft'))->toBeFalse()
        ->and($director->can_('verify_payment'))->toBeFalse();

    // The deputy carries the same powers under their own name.
    $deputy = userWithRole('deputy_executive_director');

    foreach (['approve_case', 'approve_change', 'create_case', 'verify_payment'] as $key) {
        expect($deputy->can_($key))->toBe($director->can_($key));
    }
});

it('keeps the treasurer on the money and away from the household', function () {
    $treasurer = userWithRole('treasurer');

    expect($treasurer->can_('verify_payment'))->toBeTrue()
        // Paying a family needs a file number and an amount, never the family.
        ->and($treasurer->can_('view_masked_case'))->toBeTrue()
        ->and($treasurer->can_('view_full_case'))->toBeFalse()
        ->and($treasurer->can_('approve_case'))->toBeFalse();
});

it('lets the data roles enter and check, and never approve a case', function () {
    foreach (['data_officer', 'data_supervisor', 'data_manager'] as $key) {
        $user = userWithRole($key);

        expect($user->can_('approve_case'))->toBeFalse("{$key} must not approve a case")
            ->and($user->can_('verify_payment'))->toBeFalse("{$key} must not touch money")
            ->and($user->can_('view_full_case'))->toBeTrue();
    }

    expect(userWithRole('data_officer')->can_('merge_duplicates'))->toBeFalse()
        ->and(userWithRole('data_supervisor')->can_('merge_duplicates'))->toBeTrue();
});

it('opens the panel to every role in the structure', function () {
    $panel = Filament\Facades\Filament::getPanel('admin');

    foreach ([
        'board_director', 'executive_director', 'deputy_executive_director',
        'deputy_area_supervisor', 'treasurer', 'oversight_director',
        'data_officer', 'data_supervisor', 'data_manager',
    ] as $key) {
        expect(userWithRole($key)->canAccessPanel($panel))->toBeTrue("{$key} cannot reach the panel");
    }

    // A family and a donor still have no business in it.
    expect(userWithRole('donor')->canAccessPanel($panel))->toBeFalse()
        ->and(userWithRole('beneficiary')->canAccessPanel($panel))->toBeFalse();
});
