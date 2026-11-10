<?php

use App\Filament\Pages\GovernanceDesk;
use App\Models\GovernanceAcknowledgement;
use App\Models\GovernanceApprovalRoute;
use App\Models\GovernanceDocument;
use App\Models\GovernanceDocumentVersion;
use App\Models\Role;
use App\Services\GovernanceAccess;
use App\Services\GovernanceService;
use Filament\Facades\Filament;

/*
 | T-292 — conditions 6 and 7: the acknowledgement that names a version, and
 | a screen that says what waits on this account.
 */
beforeEach(function () {
    seedCore();
    $this->service = app(GovernanceService::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    passTwoFactor();
});

/** An approved policy that staff are asked to read. */
function policyToRead(string $approverRole = 'board_director'): GovernanceDocumentVersion
{
    $document = GovernanceDocument::factory()->create([
        'kind' => 'policy',
        'classification' => 'internal',
        'requires_acknowledgement' => true,
    ]);

    GovernanceApprovalRoute::firstOrCreate([
        'kind' => 'policy',
        'role_id' => Role::where('key', $approverRole)->value('id'),
    ]);

    $version = versionOf($document);
    $approver = userWithRole($approverRole);

    app(GovernanceService::class)->submit($version, $approver);

    return app(GovernanceService::class)->approve($version, $approver);
}

it('records an acknowledgement against the version, not the document', function () {
    $version = policyToRead();
    $officer = userWithRole('case_officer');

    $ack = $this->service->acknowledge($version, $officer);

    expect($ack->version_no)->toBe($version->version_no)
        ->and($ack->acknowledged_at)->not->toBeNull()
        ->and($ack->user_id)->toBe($officer->getKey());
});

it('asks again when a new version is approved', function () {
    $first = policyToRead();
    $officer = userWithRole('case_officer');

    $this->service->acknowledge($first, $officer);

    expect($this->service->awaitingAcknowledgement($officer))->toHaveCount(0);

    // The association rewrites the policy.
    $second = versionOf($first->document, ['summary_ar' => 'تعديل المادة الرابعة']);
    $approver = userWithRole('board_director');
    $this->service->submit($second, $approver);
    $this->service->approve($second, $approver);

    $awaiting = $this->service->awaitingAcknowledgement($officer);

    expect($awaiting)->toHaveCount(1)
        ->and($awaiting->first()->getKey())->toBe($second->getKey());
});

it('writes one acknowledgement per person per version, however many times they press it', function () {
    $version = policyToRead();
    $officer = userWithRole('case_officer');

    $this->service->acknowledge($version, $officer);
    $this->service->acknowledge($version, $officer);

    expect(GovernanceAcknowledgement::where('version_id', $version->getKey())->count())->toBe(1);
});

it('never asks a person to read what they may not open', function () {
    $document = GovernanceDocument::factory()->confidential()->create([
        'kind' => 'policy',
        'requires_acknowledgement' => true,
    ]);

    GovernanceApprovalRoute::firstOrCreate([
        'kind' => 'policy',
        'role_id' => Role::where('key', 'board_director')->value('id'),
    ]);

    $version = versionOf($document);
    $approver = userWithRole('board_director');
    $this->service->submit($version, $approver);
    $this->service->approve($version, $approver);

    $officer = userWithRole('case_officer');

    expect($this->service->awaitingAcknowledgement($officer))->toHaveCount(0)
        ->and(fn () => $this->service->acknowledge($version, $officer))
        ->toThrow(RuntimeException::class);
});

it('shows the approver what waits on their decision, and nobody else’s', function () {
    $document = GovernanceDocument::factory()->create(['kind' => 'contract']);

    GovernanceApprovalRoute::create([
        'kind' => 'contract',
        'role_id' => Role::where('key', 'executive_director')->value('id'),
    ]);

    $version = versionOf($document);
    $this->service->submit($version, userWithRole('case_officer'));

    Livewire\Livewire::actingAs(userWithRole('executive_director'))
        ->test(GovernanceDesk::class)
        ->assertSee($document->title_ar);

    Livewire\Livewire::actingAs(userWithRole('board_director'))
        ->test(GovernanceDesk::class)
        ->assertDontSee($document->title_ar);
});

it('approves from the desk, through the same rule as everywhere else', function () {
    $document = GovernanceDocument::factory()->create(['kind' => 'contract']);

    GovernanceApprovalRoute::create([
        'kind' => 'contract',
        'role_id' => Role::where('key', 'executive_director')->value('id'),
    ]);

    $version = versionOf($document);
    $this->service->submit($version, userWithRole('case_officer'));

    Livewire\Livewire::actingAs(userWithRole('executive_director'))
        ->test(GovernanceDesk::class)
        ->call('approve', $version->getKey());

    expect($version->fresh()->status)->toBe('approved');

    // And the same page refuses the same act to a role that was not named.
    $other = versionOf($document);
    $this->service->submit($other, userWithRole('case_officer'));

    Livewire\Livewire::actingAs(userWithRole('board_director'))
        ->test(GovernanceDesk::class)
        ->call('approve', $other->getKey());

    expect($other->fresh()->status)->toBe('pending_approval');
});

it('shows the refused attempts to the accounts that oversee, and not to the rest', function () {
    $document = GovernanceDocument::factory()->confidential()->create();

    app(GovernanceAccess::class)
        ->check(userWithRole('case_officer'), $document, 'view');

    Livewire\Livewire::actingAs(userWithRole('board_director'))
        ->test(GovernanceDesk::class)
        ->assertSee(__('sanabel.governance.refused_attempts'));

    Livewire\Livewire::actingAs(userWithRole('case_officer'))
        ->test(GovernanceDesk::class)
        ->assertDontSee(__('sanabel.governance.refused_attempts'));
});
