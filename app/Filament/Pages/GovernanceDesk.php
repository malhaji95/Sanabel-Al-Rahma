<?php

namespace App\Filament\Pages;

use App\Models\GovernanceAccessLog;
use App\Models\GovernanceDocumentVersion;
use App\Services\GovernanceAccess;
use App\Services\GovernanceService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * T-292 — condition 7: «توفير شاشة لرئيس مجلس الأمناء لمتابعة الوثائق
 * والقرارات التي تحتاج إلى اطلاعه أو اعتماده».
 *
 * Written for the chairman and not restricted to him: the same two questions
 * — what waits on my decision, what waits on my reading — are the questions
 * every officer has, and each account sees its own answers. A screen only
 * one person may open is a screen nobody checks when that person travels.
 *
 * Nothing is decided here that is not decided elsewhere: the approval runs
 * through the same service, with the same route rule.
 */
class GovernanceDesk extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static string $view = 'filament.pages.governance-desk';

    public static function getNavigationGroup(): ?string
    {
        return __('sanabel.nav.system');
    }

    public function getTitle(): string
    {
        return __('sanabel.governance.desk');
    }

    public static function getNavigationLabel(): string
    {
        return __('sanabel.governance.desk');
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->role;
    }

    /** What is waiting on this account's decision. */
    public function awaitingApproval(): Collection
    {
        $access = app(GovernanceAccess::class);

        return GovernanceDocumentVersion::with(['document', 'submitter'])
            ->where('status', 'pending_approval')
            ->get()
            ->filter(fn (GovernanceDocumentVersion $v) => $access->allows(auth()->user(), $v->document, 'approve'))
            ->values();
    }

    /** And what is waiting on this account's reading. */
    public function awaitingAcknowledgement(): Collection
    {
        return app(GovernanceService::class)->awaitingAcknowledgement(auth()->user());
    }

    /**
     * The refused attempts of the last month, for the accounts that oversee.
     *
     * The association asked for the attempts to be recorded; a record nobody
     * reads is a record that serves nobody, so the oversight roles are shown
     * them here rather than being left to query a table.
     */
    public function refusedAttempts(): Collection
    {
        if (! $this->mayOversee()) {
            return collect();
        }

        return GovernanceAccessLog::with(['document', 'user'])
            ->where('action', 'refused')
            ->where('created_at', '>=', now()->subMonth())
            ->latest('id')
            ->limit(50)
            ->get();
    }

    public function mayOversee(): bool
    {
        return (bool) auth()->user()?->hasRole(
            'board_director', 'deputy_board_director', 'council', 'oversight_director',
        );
    }

    public function approve(int $versionId): void
    {
        $version = GovernanceDocumentVersion::findOrFail($versionId);

        try {
            app(GovernanceService::class)->approve($version, auth()->user());
            Notification::make()->success()->title(__('sanabel.governance.approved'))->send();
        } catch (\RuntimeException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }

    public function acknowledge(int $versionId): void
    {
        $version = GovernanceDocumentVersion::findOrFail($versionId);

        try {
            app(GovernanceService::class)->acknowledge($version, auth()->user());
            Notification::make()->success()->title(__('sanabel.governance.acknowledged'))->send();
        } catch (\RuntimeException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }
}
