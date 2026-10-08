<?php

use App\Filament\Pages\PermissionMatrix;
use App\Filament\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use Filament\Facades\Filament;

/*
 | Two screens the association looked for during the walkthrough and did not
 | find: the audit log, which has been written since the first week with no
 | way to read it, and the permission matrix per role.
 */

beforeEach(function () {
    seedCore();
    passTwoFactor();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('opens the audit log and shows what a change looked like before and after', function () {
    $case = publishedCase(regionWithRates());
    $case->update(['support_type' => 'one_time']);

    $this->actingAs(userWithRole('admin'))
        ->get(AuditLogResource::getUrl('index'))
        ->assertSuccessful();

    $entry = AuditLog::where('entity_type', App\Models\Beneficiary::class)
        ->where('entity_id', $case->id)
        ->where('action', 'updated')
        ->latest('id')
        ->first();

    expect($entry)->not->toBeNull()
        ->and($entry->before_json)->toHaveKey('support_type')
        ->and($entry->after_json['support_type'])->toBe('one_time')
        ->and($entry->created_at)->not->toBeNull();
});

it('never writes to the audit log from the panel', function () {
    $entry = AuditLog::create([
        'action' => 'created', 'entity_type' => 'X', 'entity_id' => 1,
    ]);

    expect(AuditLogResource::canCreate())->toBeFalse()
        ->and(fn () => $entry->update(['action' => 'updated']))->toThrow(RuntimeException::class)
        ->and(fn () => $entry->delete())->toThrow(RuntimeException::class);
});

it('keeps the log away from a role with no business in it', function () {
    foreach (['delegate', 'service_provider', 'donor'] as $role) {
        expect(userWithRole($role)->can('viewAny', AuditLog::class))
            ->toBeFalse("{$role} should not read the audit log");
    }

    // Oversight exists precisely to read it.
    expect(userWithRole('oversight_director')->can('viewAny', AuditLog::class))->toBeTrue()
        ->and(userWithRole('council')->can('viewAny', AuditLog::class))->toBeTrue();
});

it('builds the matrix from the same rows the policies read', function () {
    $page = new PermissionMatrix;
    $matrix = $page->getMatrix();

    expect($matrix['roles'])->not->toBeEmpty()
        ->and($matrix['groups']['write'])->not->toBeEmpty()
        ->and($matrix['groups']['read'])->not->toBeEmpty();

    $treasurer = Role::with('permissions')->where('key', 'treasurer')->first();
    $execute = Permission::where('key', 'execute_disbursement')->first();
    $approve = Permission::where('key', 'approve_disbursement')->first();

    expect($page->grant($treasurer, $execute))->toBe('all')
        ->and($page->grant($treasurer, $approve))->toBeNull();
});

it('strikes every write off a role that only looks', function () {
    $page = new PermissionMatrix;

    foreach (['council', 'oversight_director'] as $key) {
        $role = Role::with('permissions')->where('key', $key)->first();

        foreach (Permission::whereIn('key', App\Services\PermissionService::WRITE_PERMISSIONS)->get() as $write) {
            expect($page->grant($role, $write))->toBeNull("{$key} must show no {$write->key}");
        }

        // And the reads are still there, or the page would say nothing.
        expect($page->grant($role, Permission::where('key', 'view_reports')->first()))->toBe('all');
    }
});

it('opens the matrix to the roles that oversee, and to nobody else', function () {
    foreach (['admin', 'board_director', 'council', 'oversight_director'] as $role) {
        $this->actingAs(userWithRole($role));
        expect(PermissionMatrix::canAccess())->toBeTrue("{$role} should see the matrix");
    }

    foreach (['delegate', 'donor', 'service_provider'] as $role) {
        $this->actingAs(userWithRole($role));
        expect(PermissionMatrix::canAccess())->toBeFalse("{$role} should not see the matrix");
    }
});
