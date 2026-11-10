<?php

use App\Models\GovernanceApprovalRoute;
use App\Models\GovernanceDocument;
use App\Services\GovernanceService;

/*
 | T-283 — the first of the association's eight conditions, 9 November:
 | «حفظ جميع إصدارات الوثائق المعتمدة من المرحلة الأولى، مع منع تعديل النسخة
 | المعتمدة مباشرة».
 |
 | A record that can be changed after approval cannot be audited, so these
 | tests are about the seal and about the versions that survive it.
 */
beforeEach(function () {
    seedCore();
    $this->service = app(GovernanceService::class);
    $this->document = GovernanceDocument::factory()->create();
    $this->actor = userWithRole('executive_director');

    /*
     | T-290 — approval follows the route the association names for the kind
     | of document. These tests are about the seal and the versions, so they
     | name one and get on with it.
     */
    GovernanceApprovalRoute::create([
        'kind' => $this->document->kind,
        'role_id' => $this->actor->role_id,
    ]);
});

it('numbers each version after every version the document ever had', function () {
    $first = versionOf($this->document);
    $second = versionOf($this->document);

    expect($first->version_no)->toBe(1)->and($second->version_no)->toBe(2);

    // Even a deleted draft keeps its number out of circulation, so no two
    // people ever cite «الإصدار 2» meaning different papers.
    $second->delete();

    expect(versionOf($this->document)->version_no)->toBe(3);
});

it('refuses to let an approved version be edited', function () {
    $version = versionOf($this->document);
    $this->service->submit($version, $this->actor);
    $approved = $this->service->approve($version, $this->actor);

    expect(fn () => $approved->update(['summary_ar' => 'نصٌّ آخر']))
        ->toThrow(RuntimeException::class);

    expect($approved->fresh()->summary_ar)->toBe('أول إصدار');
});

it('refuses to let an approved version be deleted', function () {
    $version = versionOf($this->document);
    $this->service->submit($version, $this->actor);
    $approved = $this->service->approve($version, $this->actor);

    expect(fn () => $approved->delete())->toThrow(RuntimeException::class)
        ->and($approved->fresh())->not->toBeNull();
});

it('replaces an approved version with a new one instead of changing it', function () {
    $first = versionOf($this->document);
    $this->service->submit($first, $this->actor);
    $this->service->approve($first, $this->actor);

    $second = versionOf($this->document, ['summary_ar' => 'تعديل المادة الرابعة']);
    $this->service->submit($second, $this->actor);
    $this->service->approve($second, $this->actor);

    expect($first->fresh()->status)->toBe('superseded')
        ->and($first->fresh()->summary_ar)->toBe('أول إصدار')
        ->and($first->fresh()->approved_by)->toBe($this->actor->getKey())
        ->and($second->fresh()->status)->toBe('approved')
        // One version in force, never two.
        ->and($this->document->fresh()->versions()->where('status', 'approved')->count())->toBe(1)
        ->and($this->document->fresh()->currentVersion()->getKey())->toBe($second->getKey());
});

it('keeps every superseded version readable with who approved it and when', function () {
    foreach (range(1, 3) as $i) {
        $v = versionOf($this->document, ['summary_ar' => 'إصدار '.$i]);
        $this->service->submit($v, $this->actor);
        $this->service->approve($v, $this->actor);
    }

    $all = $this->document->fresh()->versions()->orderBy('version_no')->get();

    expect($all)->toHaveCount(3)
        ->and($all->pluck('status')->all())->toBe(['superseded', 'superseded', 'approved']);

    foreach ($all as $version) {
        expect($version->approved_by)->toBe($this->actor->getKey())
            ->and($version->approved_at)->not->toBeNull()
            ->and($version->summary_ar)->toBe('إصدار '.$version->version_no);
    }
});

it('refuses to submit a version with no file attached', function () {
    $empty = $this->service->draft($this->document, ['summary_ar' => 'بلا ملف']);

    expect(fn () => $this->service->submit($empty, $this->actor))
        ->toThrow(RuntimeException::class);
});

it('sends a refused version back without touching the one in force', function () {
    $first = versionOf($this->document);
    $this->service->submit($first, $this->actor);
    $this->service->approve($first, $this->actor);

    $second = versionOf($this->document);
    $this->service->submit($second, $this->actor);
    $this->service->reject($second, $this->actor, 'ينقصها توقيع المجلس');

    expect($second->fresh()->status)->toBe('rejected')
        ->and($second->fresh()->decision_note_ar)->toBe('ينقصها توقيع المجلس')
        ->and($first->fresh()->status)->toBe('approved')
        ->and($this->document->fresh()->status)->toBe('approved');
});

it('approves nothing that is not waiting for approval', function () {
    $version = versionOf($this->document);

    expect(fn () => $this->service->approve($version, $this->actor))
        ->toThrow(RuntimeException::class);
});
