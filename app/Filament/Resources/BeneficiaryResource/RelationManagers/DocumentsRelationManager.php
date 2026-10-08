<?php

namespace App\Filament\Resources\BeneficiaryResource\RelationManagers;

use App\Models\Media;
use App\Services\MediaService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The family's own papers on the family's own file: the identity document,
 * the lease, a medical report, a debt paper.
 *
 * The store was built in the first weeks and nothing ever attached anything
 * to a household through it, which the association found during the
 * walkthrough. Nothing here changes how the store works: the file goes to
 * the private disk and is reached only through a link that expires in
 * minutes, and no donor ever sees one.
 */
class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('sanabel.document.plural');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->latest('id'))
            ->columns([
                Tables\Columns\TextColumn::make('kind')
                    ->label(__('sanabel.document.kind'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __('sanabel.document.kinds.'.$state) === 'sanabel.document.kinds.'.$state
                        ? $state
                        : __('sanabel.document.kinds.'.$state)),
                Tables\Columns\TextColumn::make('mime')->label(__('sanabel.document.type'))->toggleable(),
                Tables\Columns\TextColumn::make('size_bytes')
                    ->label(__('sanabel.document.size'))
                    ->formatStateUsing(fn (?int $state) => $state ? number_format($state / 1024).' KB' : '—'),
                Tables\Columns\TextColumn::make('uploader.name')
                    ->label(__('sanabel.document.uploaded_by'))->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('sanabel.document.uploaded_at'))->dateTime('Y-m-d H:i'),
            ])
            ->headerActions([
                Tables\Actions\Action::make('upload')
                    ->label(__('sanabel.document.upload'))
                    ->icon('heroicon-o-arrow-up-tray')
                    ->visible(fn () => auth()->user()?->can_('upload_media'))
                    ->form([
                        Forms\Components\Select::make('kind')
                            ->label(__('sanabel.document.kind'))
                            ->options(collect(Media::FAMILY_KINDS)
                                ->mapWithKeys(fn (string $k) => [$k => __('sanabel.document.kinds.'.$k)])
                                ->all())
                            ->required(),

                        // Held on the local disk only for the moment it takes
                        // the service to move it to the private one.
                        Forms\Components\FileUpload::make('file')
                            ->label(__('sanabel.document.file'))
                            ->required()
                            ->maxSize(8192)
                            ->storeFiles(false)
                            ->helperText(__('sanabel.document.file_help')),
                    ])
                    ->action(function (array $data) {
                        app(MediaService::class)->store(
                            $data['file'],
                            $this->getOwnerRecord(),
                            $data['kind'],
                            auth()->user(),
                        );

                        Notification::make()->title(__('sanabel.document.uploaded'))->success()->send();
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('open')
                    ->label(__('sanabel.document.open'))
                    ->icon('heroicon-o-eye')
                    ->url(fn (Media $record) => app(MediaService::class)->temporaryUrl($record))
                    ->openUrlInNewTab(),

                // Rule 3 — archived, never erased.
                Tables\Actions\DeleteAction::make()
                    ->label(__('sanabel.document.archive'))
                    ->visible(fn () => auth()->user()?->can_('upload_media')),
            ])
            ->paginated([10, 25, 50]);
    }
}
