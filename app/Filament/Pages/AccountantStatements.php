<?php

namespace App\Filament\Pages;

use App\Models\DonationImport;
use App\Services\AccountantStatement;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * T-322 — phase two, item 10: «استيراد ملفّات المحاسب».
 *
 * Upload, read, and only then believe. The page shows every line's verdict
 * before anything is approved, because «معاينة قبل الإدخال» is the whole point
 * of the item: a reconciliation nobody read is a reconciliation nobody checked.
 */
class AccountantStatements extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-document-check';

    protected static string $view = 'filament.pages.accountant-statements';

    public static function getNavigationGroup(): ?string
    {
        return __('sanabel.nav.money');
    }

    public function getTitle(): string
    {
        return __('sanabel.statement.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('sanabel.statement.plural');
    }

    /**
     * Whoever verifies a transfer by hand is whoever may do it by the
     * statement: this screen writes nothing a verifier could not write
     * one at a time on the donations queue.
     */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can_('verify_payment');
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('upload')
                ->label(__('sanabel.statement.upload'))
                ->icon('heroicon-o-arrow-up-tray')
                ->form([
                    Forms\Components\FileUpload::make('file')
                        ->label(__('sanabel.statement.file'))
                        ->helperText(__('sanabel.statement.file_help'))
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])
                        ->storeFiles(false)
                        ->required(),
                ])
                ->action(function (array $data) {
                    try {
                        $import = app(AccountantStatement::class)
                            ->stage($data['file'], auth()->user());
                    } catch (\RuntimeException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()
                        ->title(__('sanabel.statement.staged', [
                            'count' => $import->rows()->count(),
                            'ready' => $import->rows()->where('outcome', AccountantStatement::READY)->count(),
                        ]))
                        ->success()
                        ->send();
                }),
        ];
    }

    /** The statements, newest first, with their rows for the preview. */
    public function statements()
    {
        return DonationImport::with(['rows' => fn ($q) => $q->orderBy('row_no'), 'uploader', 'approver'])
            ->latest('id')
            ->limit(20)
            ->get();
    }

    public function approve(int $id): void
    {
        $import = DonationImport::findOrFail($id);

        try {
            $verified = app(AccountantStatement::class)->approve($import, auth()->user());
        } catch (\RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()
            ->title(__('sanabel.statement.approved', ['count' => $verified]))
            ->success()
            ->send();
    }

    public function discard(int $id, string $reason = ''): void
    {
        $import = DonationImport::findOrFail($id);

        try {
            app(AccountantStatement::class)->discard($import, auth()->user(), $reason);
        } catch (\RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title(__('sanabel.statement.discarded'))->success()->send();
    }
}
