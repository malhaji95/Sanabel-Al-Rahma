<?php

use App\Filament\Pages\ApprovalFollowUp;
use App\Filament\Pages\GovernanceDesk;
use App\Filament\Pages\PermissionMatrix;
use App\Models\AuditLog;
use App\Models\Role;
use App\Services\PermissionService;
use Filament\Facades\Filament;

/*
 | T-312 — phase two, item 1. «لدينا مسؤول العمليات … هو هذا الشخص الي عنده
 | شاشة الي يقدر يتابعه الكل ويوجه الكل إلى اكمال حالات المتوقفة عنده بدون ما
 | يقوم بي اي تعديل», 20 November.
 |
 | The screen they described was already built — «متابعة الاعتمادات», which
 | shows every held file by whose desk it waits on and for how long. What was
 | missing was an account that may open it and nothing else. The nearest role,
 | the oversight director, carries one key too many: the confidential
 | complaints are the oversight committee's to read, and chasing a stalled
 | file is not that.
 |
 | So: the same keys, less that one, and read-only enforced by the role itself
 | rather than by the matrix, which a later edit could change.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    passTwoFactor();

    $this->officer = userWithRole('operations_officer');
});

it('holds the oversight keys less the confidential complaints, and no others', function () {
    $role = Role::with('permissions')->where('key', 'operations_officer')->firstOrFail();

    $held = $role->permissions
        ->mapWithKeys(fn ($p) => [$p->key => $p->pivot->scope])
        ->all();

    expect($role->is_read_only)->toBeTrue()
        ->and($held)->toBe([
            'view_full_case' => 'all',
            'view_masked_case' => 'all',
            'search_by_national_id' => 'all',
            'view_reports' => 'all',
        ]);

    // The oversight director's own sheet, for the difference to be the point
    // of this role rather than a coincidence of two lists.
    $oversight = Role::with('permissions')->where('key', 'oversight_director')->firstOrFail();

    expect($oversight->permissions->pluck('key')->diff($role->permissions->pluck('key'))->values()->all())
        ->toBe(['read_confidential_complaint']);
})->group('AUTH-01');

it('refuses him every write, whatever the matrix says', function () {
    $this->actingAs($this->officer);

    foreach ([
        'create_case', 'edit_draft', 'approve_case', 'request_change', 'approve_change',
        'merge_duplicates', 'upload_media', 'confirm_disbursement', 'raise_disbursement_order',
        'execute_disbursement', 'verify_payment', 'manage_users', 'waive_conflict',
    ] as $key) {
        expect($this->officer->can_($key))->toBeFalse("operations_officer must not hold {$key}");
    }
})->group('AUTH-01');

it('opens the held-files screen to him, because that is the screen he was described by', function () {
    $this->actingAs($this->officer);

    expect(ApprovalFollowUp::canAccess())->toBeTrue()
        ->and(app(PermissionService::class)->scopeFor($this->officer, 'view_reports'))->toBe('all');
})->group('AUTH-01');

it('lets him into the panel the files are held in', function () {
    expect($this->officer->canAccessPanel(Filament::getPanel('admin')))->toBeTrue()
        ->and($this->officer->canAccessPanel(Filament::getPanel('association')))->toBeFalse();
})->group('AUTH-01');

it('leaves him out of the matrix, the audit log and the governance desk', function () {
    /*
     | Those three are the oversight accounts', and they are gated by role
     | name rather than by a key. He was promised the files, the reports and
     | the one screen — not the record of who did what to whom.
     */
    $this->actingAs($this->officer);

    expect(PermissionMatrix::canAccess())->toBeFalse()
        ->and($this->officer->can('viewAny', AuditLog::class))->toBeFalse()
        ->and((new GovernanceDesk)->mayOversee())->toBeFalse();
})->group('AUTH-01');

it('is named in Arabic, like every other role on the screen', function () {
    expect(Role::where('key', 'operations_officer')->value('name_ar'))->toBe('مسؤول العمليات');
});
