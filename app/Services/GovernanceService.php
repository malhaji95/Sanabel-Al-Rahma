<?php

namespace App\Services;

use App\Models\GovernanceDocument;
use App\Models\GovernanceDocumentVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * T-283 — the life of a governed document's version.
 *
 * Only four things happen to a version: it is drafted, it is submitted, and
 * then it is approved or refused. Approving one supersedes the one it
 * replaces, in a transaction, so a document can never hold two versions in
 * force at the same moment.
 */
class GovernanceService
{
    /** A new draft version, numbered after every version the document ever had. */
    public function draft(GovernanceDocument $document, array $attributes = []): GovernanceDocumentVersion
    {
        return DB::transaction(function () use ($document, $attributes) {
            // Locked while the number is taken, so two researchers drafting at
            // once cannot both be told they are version four.
            $locked = GovernanceDocument::whereKey($document->getKey())->lockForUpdate()->firstOrFail();

            return $locked->versions()->create(array_merge($attributes, [
                'version_no' => $locked->nextVersionNo(),
                'status' => 'draft',
            ]));
        });
    }

    public function submit(GovernanceDocumentVersion $version, User $actor): GovernanceDocumentVersion
    {
        if ($version->status !== 'draft') {
            throw new \RuntimeException(__('sanabel.governance.only_a_draft_is_submitted'));
        }

        if ($version->media_id === null && $version->files()->count() === 0) {
            throw new \RuntimeException(__('sanabel.governance.no_file_to_approve'));
        }

        $version->forceFill([
            'status' => 'pending_approval',
            'submitted_by' => $actor->getKey(),
            'submitted_at' => now(),
        ])->save();

        $version->document()->update(['status' => 'pending_approval']);

        return $version->refresh();
    }

    /**
     * Approve a version, and supersede whatever it replaces.
     *
     * The superseding is the only change a sealed version accepts, and it is
     * done here rather than left to a reader to infer from the numbers.
     */
    public function approve(GovernanceDocumentVersion $version, User $actor, ?string $note = null): GovernanceDocumentVersion
    {
        if ($version->status !== 'pending_approval') {
            throw new \RuntimeException(__('sanabel.governance.only_pending_is_approved'));
        }

        return DB::transaction(function () use ($version, $actor, $note) {
            $document = GovernanceDocument::whereKey($version->document_id)->lockForUpdate()->firstOrFail();

            foreach ($document->versions()->where('status', 'approved')->get() as $older) {
                $older->status = 'superseded';
                $older->save();
            }

            $version->forceFill([
                'status' => 'approved',
                'approved_by' => $actor->getKey(),
                'approved_at' => now(),
                'decision_note_ar' => $note,
            ])->save();

            $document->update(['status' => 'approved']);

            return $version->refresh();
        });
    }

    public function reject(GovernanceDocumentVersion $version, User $actor, string $note): GovernanceDocumentVersion
    {
        if ($version->status !== 'pending_approval') {
            throw new \RuntimeException(__('sanabel.governance.only_pending_is_approved'));
        }

        $version->forceFill([
            'status' => 'rejected',
            'approved_by' => $actor->getKey(),
            'approved_at' => now(),
            'decision_note_ar' => $note,
        ])->save();

        // The document goes back to whatever it was: approved if a version is
        // still in force, a draft if none ever was.
        $version->document()->update([
            'status' => $version->document->currentVersion() ? 'approved' : 'draft',
        ]);

        return $version->refresh();
    }
}
