<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DisbursementResource\Pages;
use App\Models\Beneficiary;
use App\Models\Disbursement;
use App\Services\CoverageService;
use App\Services\DisbursementService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

/**
 * The first step of the money path: the case officer confirms what a family
 * is owed this month. The treasurer then gathers confirmed lines into one
 * order from the bulk action at the foot of this list.
 */
class DisbursementResource extends Resource
{
    protected static ?string $model = Disbursement::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    public static function getNavigationGroup(): ?string
    {
        return __('sanabel.nav.money');
    }

    public static function getModelLabel(): string
    {
        return __('sanabel.disbursement.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('sanabel.disbursement.plural');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('beneficiary_id')
                ->label(__('sanabel.beneficiary.singular'))
                ->relationship('beneficiary', 'file_number')
                ->searchable()
                ->preload()
                ->required()
                ->live()
                ->afterStateUpdated(function (Forms\Set $set, $state) {
                    $family = Beneficiary::find($state);

                    $set('remaining_hint', $family
                        ? number_format(app(DisbursementService::class)
                            ->remainingForPeriod($family, now()->startOfMonth()))
                        : null);

                    // T-141 — the stage the association is collecting for,
                    // beside the need it is a share of.
                    $set('target_hint', $family
                        ? number_format(app(CoverageService::class)->targetAmount($family))
                            .' ('.app(CoverageService::class)->targetPercent($family).'%)'
                        : null);
                }),

            /*
             | T-141 — «المبلغ المتبقي من احتياج الاسرة هذا الشهر: هل هذا
             | الاحتياج الأساسي المتبقي أم مبلغ التمويل الذي هو مثلا 30
             | بالمئة؟», 28 October.
             |
             | The first: the family's whole monthly need, less what has
             | already been confirmed for the same month and less what another
             | body paid for it. The funding stage is a fundraising figure —
             | what the association is asking donors for now — and it is not a
             | ceiling on what a family may be paid when the money is there.
             |
             | Both are on the screen now, each named, because the question was
             | asked of a label that gave one number and said neither.
             */
            Forms\Components\Placeholder::make('remaining_hint')
                ->label(__('sanabel.disbursement.remaining_this_month'))
                ->helperText(__('sanabel.disbursement.remaining_help'))
                ->content(fn (Forms\Get $get) => $get('remaining_hint') ?? '—'),

            Forms\Components\Placeholder::make('target_hint')
                ->label(__('sanabel.disbursement.target_this_month'))
                ->helperText(__('sanabel.disbursement.target_help'))
                ->content(fn (Forms\Get $get) => $get('target_hint') ?? '—'),

            Forms\Components\TextInput::make('amount')
                ->label(__('sanabel.disbursement.amount'))
                ->numeric()
                ->minValue(1)
                ->required()
                ->helperText(__('sanabel.disbursement.amount_help')),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                /*
                 | T-124 — «دخلت من حساب رئيس الأمناء على طلبات الصرف بس مافي
                 | بيانات العائلة او اني اقدر اضغط على بيانات الاسرة», 26
                 | October.
                 |
                 | The file number was a string on a money row and nothing
                 | else, so the one question an approver has — who is this
                 | for? — could not be answered from the screen they approve
                 | on. It opens the family's own file now, for an account
                 | that may read it, and stays a plain string for one that
                 | may not: the link is the permission, not a decoration.
                 */
                Tables\Columns\TextColumn::make('beneficiary.file_number')
                    ->label(__('sanabel.beneficiary.file_number'))->searchable()->sortable()
                    /*
                     | T-141 — «اول شي حالة امر صرف ما عم تفتح, لازم تفتح لان
                     | المدير التنفيذي ممكن يحتاج يطلع على الحالة قبل اعتماد»,
                     | 28 October.
                     |
                     | The link was right and the destination was not: it
                     | pointed at the edit screen, which opens for `edit_draft`
                     | — a key the executive director does not hold and should
                     | not. So the one account that most needs to read a family
                     | before approving money for them was sent to a door that
                     | answers it no. It opens the read-only screen now, which
                     | carries the whole file and, since T-133, its decisions.
                     */
                    ->url(fn (Disbursement $record) => $record->beneficiary
                        && auth()->user()->can('view', $record->beneficiary)
                            ? BeneficiaryResource::getUrl('view', ['record' => $record->beneficiary_id])
                            : null)
                    ->openUrlInNewTab()
                    ->color(fn (Disbursement $record) => $record->beneficiary
                        && auth()->user()->can('view', $record->beneficiary) ? 'primary' : null)
                    ->tooltip(fn (Disbursement $record) => $record->beneficiary
                        && auth()->user()->can('view', $record->beneficiary)
                            ? __('sanabel.disbursement.open_case')
                            : null),

                // The region, so a row says where the money is going without
                // opening anything at all. Rule 15 — the area, never the
                // village, is what a page outside the file may carry.
                Tables\Columns\TextColumn::make('beneficiary.region.name_ar')
                    ->label(__('sanabel.beneficiary.region'))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('amount')
                    ->label(__('sanabel.disbursement.amount'))->numeric()->sortable(),
                Tables\Columns\TextColumn::make('period')
                    ->label(__('sanabel.disbursement.period'))->date('Y-m')->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('sanabel.beneficiary.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __('sanabel.disbursement.statuses.'.$state))
                    ->color(fn (string $state) => match ($state) {
                        'reconciled', 'executed' => 'success',
                        'approved' => 'info',
                        'in_order' => 'warning',
                        'rejected', 'failed' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('order.reference_no')
                    ->label(__('sanabel.disbursement.reference_no'))
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('confirmedBy.name')
                    ->label(__('sanabel.disbursement.confirmed_by'))->toggleable(),

                /*
                 | T-214 — «إغلاق متابعة المستفيد غير إغلاق التدقيق المالي»,
                 | 1 November. Two closings, so two readings: the status
                 | column above says what happened to the money, and this
                 | one says what the data desk has done about the line.
                 */
                Tables\Columns\TextColumn::make('followup_closed_at')
                    ->label(__('sanabel.disbursement.followup'))
                    ->badge()
                    ->state(fn (Disbursement $record) => match (true) {
                        $record->followup_closed_at !== null => __('sanabel.disbursement.followup_closed_badge'),
                        $record->returned_to_treasurer_at !== null => __('sanabel.disbursement.followup_with_treasurer'),
                        in_array($record->status, ['received', 'disputed'], true) => __('sanabel.disbursement.followup_open'),
                        default => null,
                    })
                    ->color(fn (Disbursement $record) => match (true) {
                        $record->followup_closed_at !== null => 'success',
                        $record->returned_to_treasurer_at !== null => 'warning',
                        default => 'gray',
                    })
                    ->placeholder(''),
            ])
            ->actions([
                /*
                 | T-214 — «ردود المستفيدين بترجع عنده بصفحة التبرعات،
                 | وبيشوف مين استلم ومين ما استلم. اللي أكد الاستلام وكان
                 | عنده إثبات تحويل صحيح، بيقفل متابعة الطلب. واللي ما
                 | استلم أو عنده مشكلة، بيرجعه لأمين الصندوق للمعالجة،
                 | وبيظل مفتوح لحد ما تنحل المشكلة», 1 November.
                 |
                 | The desk could read both answers on this screen and act
                 | on neither. These are its two acts, and they belong to
                 | the key it already holds — the one that put the line here
                 | in the first place.
                 */
                Tables\Actions\Action::make('closeFollowUp')
                    ->label(__('sanabel.disbursement.close_followup'))
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Disbursement $record) => $record->status === 'received'
                        && $record->followup_closed_at === null
                        && auth()->user()?->can_('confirm_disbursement'))
                    ->requiresConfirmation()
                    ->modalDescription(__('sanabel.disbursement.close_followup_help'))
                    ->action(function (Disbursement $record) {
                        try {
                            app(DisbursementService::class)->closeFollowUp($record, auth()->user());
                        } catch (\RuntimeException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title(__('sanabel.disbursement.followup_closed'))->success()->send();
                    }),

                Tables\Actions\Action::make('returnToTreasurer')
                    ->label(__('sanabel.disbursement.return_to_treasurer'))
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->visible(fn (Disbursement $record) => $record->status === 'disputed'
                        && auth()->user()?->can_('confirm_disbursement'))
                    ->requiresConfirmation()
                    ->modalDescription(__('sanabel.disbursement.return_to_treasurer_help'))
                    ->action(function (Disbursement $record) {
                        try {
                            app(DisbursementService::class)->returnToTreasurer($record, auth()->user());
                        } catch (\RuntimeException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title(__('sanabel.disbursement.returned_to_treasurer'))->success()->send();
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('sanabel.beneficiary.status'))
                    ->options(collect(Disbursement::STATUSES)
                        ->mapWithKeys(fn (string $s) => [$s => __('sanabel.disbursement.statuses.'.$s)])
                        ->all()),
            ])
            ->bulkActions([
                // The treasurer's step: gather confirmed lines into one order.
                Tables\Actions\BulkAction::make('gather')
                    ->label(__('sanabel.disbursement.gather'))
                    ->icon('heroicon-o-rectangle-stack')
                    ->visible(fn () => auth()->user()?->can_('raise_disbursement_order'))
                    ->requiresConfirmation()
                    ->action(function (Collection $records) {
                        $confirmed = $records->where('status', 'confirmed');

                        if ($confirmed->isEmpty()) {
                            Notification::make()
                                ->title(__('sanabel.disbursement.only_confirmed_join'))
                                ->danger()->send();

                            return;
                        }

                        $order = app(DisbursementService::class)
                            ->gather($confirmed, auth()->user());

                        Notification::make()
                            ->title(__('sanabel.disbursement.gathered', [
                                'ref' => $order->reference_no,
                                'count' => $confirmed->count(),
                            ]))
                            ->success()->send();
                    }),
            ])
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDisbursements::route('/'),
            'create' => Pages\CreateDisbursement::route('/create'),
        ];
    }
}
