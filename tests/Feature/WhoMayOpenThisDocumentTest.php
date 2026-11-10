<?php

use App\Models\GovernanceApprovalRoute;
use App\Models\GovernanceDocument;
use App\Models\GovernanceDocumentGrant;
use App\Models\Role;
use App\Services\GovernanceAccess;
use App\Services\GovernanceService;

/*
 | T-290 — conditions 3, 4 and 8 of the governance section.
 |
 | The classification is the floor, a grant lifts one document above it for
 | one role, the approver is named per kind of document, and the technical
 | account reads nothing confidential without being named.
 */
beforeEach(function () {
    seedCore();
    $this->access = app(GovernanceAccess::class);
    $this->service = app(GovernanceService::class);
});

function roleOf(string $key): Role
{
    return Role::where('key', $key)->firstOrFail();
}

it('lets anybody who works here read an internal document, and nobody read a confidential one', function () {
    $internal = GovernanceDocument::factory()->create(['classification' => 'internal']);
    $confidential = GovernanceDocument::factory()->confidential()->create();

    $officer = userWithRole('case_officer');

    expect($this->access->allows($officer, $internal, 'view'))->toBeTrue()
        ->and($this->access->allows($officer, $confidential, 'view'))->toBeFalse();
});

it('keeps an account that is served by the system out of everything but the public', function () {
    $public = GovernanceDocument::factory()->create(['classification' => 'public']);
    $internal = GovernanceDocument::factory()->create(['classification' => 'internal']);

    $donor = userWithRole('donor');

    expect($this->access->allows($donor, $public, 'view'))->toBeTrue()
        ->and($this->access->allows($donor, $internal, 'view'))->toBeFalse();
});

it('opens one document to one role without opening the rest', function () {
    $one = GovernanceDocument::factory()->confidential()->create();
    $other = GovernanceDocument::factory()->confidential()->create();

    $officer = userWithRole('case_officer');

    GovernanceDocumentGrant::create([
        'document_id' => $one->getKey(),
        'role_id' => roleOf('case_officer')->getKey(),
        'ability' => 'view',
    ]);

    expect($this->access->allows($officer, $one, 'view'))->toBeTrue()
        // The grant was for one document and one ability, and is neither
        // a classification nor a habit.
        ->and($this->access->allows($officer, $one, 'download'))->toBeFalse()
        ->and($this->access->allows($officer, $other, 'view'))->toBeFalse();
});

it('keeps the technical account out of what the association guards', function () {
    $confidential = GovernanceDocument::factory()->confidential()->create();
    $internal = GovernanceDocument::factory()->create(['classification' => 'internal']);

    $admin = userWithRole('admin');

    expect($this->access->allows($admin, $confidential, 'view'))->toBeFalse()
        ->and($this->access->allows($admin, $confidential, 'download'))->toBeFalse()
        // It still prepares the system, so what is not guarded is open.
        ->and($this->access->allows($admin, $internal, 'view'))->toBeTrue();
});

it('lets the technical account in only where the association wrote it down', function () {
    $confidential = GovernanceDocument::factory()->confidential()->create();
    $admin = userWithRole('admin');

    GovernanceDocumentGrant::create([
        'document_id' => $confidential->getKey(),
        'role_id' => roleOf('admin')->getKey(),
        'ability' => 'view',
    ]);

    expect($this->access->allows($admin, $confidential, 'view'))->toBeTrue()
        ->and($this->access->allows($admin, $confidential, 'download'))->toBeFalse();
});

it('names the approver by the kind of document, not by one office', function () {
    $policy = GovernanceDocument::factory()->create(['kind' => 'policy']);
    $contract = GovernanceDocument::factory()->create(['kind' => 'contract']);

    GovernanceApprovalRoute::create(['kind' => 'policy', 'role_id' => roleOf('board_director')->getKey()]);
    GovernanceApprovalRoute::create(['kind' => 'contract', 'role_id' => roleOf('executive_director')->getKey()]);

    $board = userWithRole('board_director');
    $executive = userWithRole('executive_director');

    expect($this->access->allows($board, $policy, 'approve'))->toBeTrue()
        ->and($this->access->allows($executive, $policy, 'approve'))->toBeFalse()
        ->and($this->access->allows($executive, $contract, 'approve'))->toBeTrue()
        ->and($this->access->allows($board, $contract, 'approve'))->toBeFalse();
});

it('refuses an approval from a role the association did not name', function () {
    $document = GovernanceDocument::factory()->create(['kind' => 'policy']);
    GovernanceApprovalRoute::create(['kind' => 'policy', 'role_id' => roleOf('board_director')->getKey()]);

    $version = versionOf($document);
    $this->service->submit($version, userWithRole('case_officer'));

    expect(fn () => $this->service->approve($version, userWithRole('executive_director')))
        ->toThrow(RuntimeException::class);

    expect($version->fresh()->status)->toBe('pending_approval');
});

it('takes the approval from the role it did name', function () {
    $document = GovernanceDocument::factory()->create(['kind' => 'policy']);
    GovernanceApprovalRoute::create(['kind' => 'policy', 'role_id' => roleOf('board_director')->getKey()]);

    $version = versionOf($document);
    $this->service->submit($version, userWithRole('case_officer'));

    $approved = $this->service->approve($version, userWithRole('board_director'));

    expect($approved->status)->toBe('approved');
});

it('says who is waited on, for a screen that has to tell somebody', function () {
    $document = GovernanceDocument::factory()->create(['kind' => 'minutes']);
    GovernanceApprovalRoute::create(['kind' => 'minutes', 'role_id' => roleOf('board_director')->getKey()]);

    expect($this->access->approverNames($document))
        ->toBe([roleOf('board_director')->name_ar]);
});
