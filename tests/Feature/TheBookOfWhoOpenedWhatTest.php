<?php

use App\Models\GovernanceAccessLog;
use App\Models\GovernanceApprovalRoute;
use App\Models\GovernanceDocument;
use App\Models\GovernanceDocumentGrant;
use App\Models\Role;
use App\Services\GovernanceAccess;
use App\Services\GovernanceService;
use Illuminate\Support\Facades\Storage;

/*
 | T-291 — condition 5: «تسجيل فتح وتنزيل الوثائق السرية ومحاولات الوصول غير
 | المصرح بها».
 |
 | Its own book, not a widening of the audit log: rule 4 keeps that one for
 | writes and says reads are not logged in phase 1. The refusals matter most
 | — a read by the right person tells you nothing later.
 */
beforeEach(function () {
    seedCore();
    $this->access = app(GovernanceAccess::class);
    $this->document = GovernanceDocument::factory()->confidential()->create();
});

it('writes down an opening of a guarded document', function () {
    $officer = userWithRole('case_officer');

    GovernanceDocumentGrant::create([
        'document_id' => $this->document->getKey(),
        'role_id' => Role::where('key', 'case_officer')->value('id'),
        'ability' => 'view',
    ]);

    expect($this->access->check($officer, $this->document, 'view'))->toBeTrue();

    $log = GovernanceAccessLog::latest('id')->first();

    expect($log->action)->toBe('opened')
        ->and($log->user_id)->toBe($officer->getKey())
        ->and($log->classification)->toBe('confidential');
});

it('writes down a refusal, which is the line that matters', function () {
    $officer = userWithRole('case_officer');

    expect($this->access->check($officer, $this->document, 'download'))->toBeFalse();

    $log = GovernanceAccessLog::latest('id')->first();

    expect($log->action)->toBe('refused')
        ->and($log->ability)->toBe('download')
        ->and($log->user_id)->toBe($officer->getKey());
});

it('writes down the technical account being turned away', function () {
    expect($this->access->check(userWithRole('admin'), $this->document, 'view'))->toBeFalse();

    expect(GovernanceAccessLog::latest('id')->first()->action)->toBe('refused');
});

it('does not fill the book with glances at the staff handbook', function () {
    $internal = GovernanceDocument::factory()->create(['classification' => 'internal']);

    $this->access->check(userWithRole('case_officer'), $internal, 'view');

    expect(GovernanceAccessLog::count())->toBe(0);
});

it('is a book nobody rewrites', function () {
    $this->access->check(userWithRole('case_officer'), $this->document, 'view');
    $log = GovernanceAccessLog::latest('id')->firstOrFail();

    expect(fn () => $log->update(['action' => 'opened']))->toThrow(RuntimeException::class)
        ->and(fn () => $log->delete())->toThrow(RuntimeException::class);
});

it('serves a guarded document through the application, and records the opening', function () {
    Storage::fake(config('sanabel.media_disk'));

    $version = versionOf($this->document);
    Storage::disk(config('sanabel.media_disk'))->put($version->media->storage_key, 'pdf-bytes');

    GovernanceApprovalRoute::create([
        'kind' => $this->document->kind,
        'role_id' => Role::where('key', 'executive_director')->value('id'),
    ]);

    $approver = userWithRole('executive_director');
    app(GovernanceService::class)->submit($version, $approver);
    app(GovernanceService::class)->approve($version, $approver);

    GovernanceDocumentGrant::create([
        'document_id' => $this->document->getKey(),
        'role_id' => $approver->role_id,
        'ability' => 'view',
    ]);

    $this->actingAs($approver)
        ->get(route('governance.document', $version))
        ->assertSuccessful();

    expect(GovernanceAccessLog::where('action', 'opened')->count())->toBe(1);
});

it('turns away a reader with no business there, and writes that down too', function () {
    Storage::fake(config('sanabel.media_disk'));

    $version = versionOf($this->document);
    Storage::disk(config('sanabel.media_disk'))->put($version->media->storage_key, 'pdf-bytes');

    $this->actingAs(userWithRole('case_officer'))
        ->get(route('governance.document', $version))
        ->assertForbidden();

    expect(GovernanceAccessLog::where('action', 'refused')->count())->toBe(1);
});

it('lets nobody at it without signing in', function () {
    $version = versionOf($this->document);

    $this->get(route('governance.document', $version))->assertRedirect();
});
