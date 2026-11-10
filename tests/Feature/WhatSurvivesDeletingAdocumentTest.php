<?php

use App\Models\GovernanceAccessLog;
use App\Models\GovernanceAcknowledgement;
use App\Models\GovernanceApprovalRoute;
use App\Models\GovernanceDocument;
use App\Models\GovernanceDocumentVersion;
use App\Models\Role;
use App\Services\GovernanceAccess;
use App\Services\GovernanceService;

/*
 | T-293 — the association's closing paragraph, 9 November: «نرجو منكم كذلك
 | التأكد من أن النسخ الاحتياطية وسجلات التدقيق قابلة للاستعادة والتحقق، وأن
 | حذف الوثائق لا يؤدي إلى فقدان السجل التاريخي».
 |
 | The restore is proved by scripts/verify-restore.sh, which backs up, restores
 | into a throwaway database and compares row counts — the governance tables
 | among them since this task. This file holds the other half: what is left
 | when somebody deletes a document.
 */
beforeEach(function () {
    seedCore();
    $this->service = app(GovernanceService::class);

    $this->document = GovernanceDocument::factory()->create([
        'kind' => 'policy',
        'requires_acknowledgement' => true,
    ]);

    GovernanceApprovalRoute::create([
        'kind' => 'policy',
        'role_id' => Role::where('key', 'board_director')->value('id'),
    ]);

    $this->approver = userWithRole('board_director');
});

it('keeps the versions, the approvals and the dates when a document is deleted', function () {
    $version = versionOf($this->document);
    $this->service->submit($version, $this->approver);
    $this->service->approve($version, $this->approver);

    $this->document->delete();

    // `fresh()` is not used here: it queries without the model's scopes and
    // so hands back a soft-deleted row, which would prove nothing.
    expect(GovernanceDocument::find($this->document->getKey()))->toBeNull()
        ->and(GovernanceDocument::withTrashed()->find($this->document->getKey()))->not->toBeNull();

    $kept = GovernanceDocumentVersion::find($version->getKey());

    expect($kept)->not->toBeNull()
        ->and($kept->status)->toBe('approved')
        ->and($kept->approved_by)->toBe($this->approver->getKey())
        ->and($kept->approved_at)->not->toBeNull();
});

it('keeps the book of who opened it', function () {
    $confidential = GovernanceDocument::factory()->confidential()->create();

    app(GovernanceAccess::class)->check(userWithRole('case_officer'), $confidential, 'view');

    expect(GovernanceAccessLog::count())->toBe(1);

    $confidential->delete();

    expect(GovernanceAccessLog::count())->toBe(1)
        ->and(GovernanceAccessLog::first()->action)->toBe('refused');
});

it('keeps who acknowledged which version', function () {
    $version = versionOf($this->document);
    $this->service->submit($version, $this->approver);
    $this->service->approve($version, $this->approver);

    $officer = userWithRole('case_officer');
    $this->service->acknowledge($version, $officer);

    $this->document->delete();

    $ack = GovernanceAcknowledgement::where('user_id', $officer->getKey())->first();

    expect($ack)->not->toBeNull()
        ->and($ack->version_no)->toBe($version->version_no);
});

it('refuses to delete an approved version even through the document', function () {
    $version = versionOf($this->document);
    $this->service->submit($version, $this->approver);
    $this->service->approve($version, $this->approver);

    // The seal holds whoever asks: a sealed version is not deleted.
    expect(fn () => $version->delete())->toThrow(RuntimeException::class);
});

it('names the governance tables in the restore check, so a restore proves them', function () {
    $script = file_get_contents(base_path('scripts/verify-restore.sh'));

    foreach ([
        'governance_documents',
        'governance_document_versions',
        'governance_access_logs',
        'governance_acknowledgements',
        'need_additions',
        'audit_log',
    ] as $table) {
        expect($script)->toContain($table);
    }
});
