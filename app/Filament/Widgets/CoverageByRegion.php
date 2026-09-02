<?php

namespace App\Filament\Widgets;

use App\Models\Beneficiary;
use App\Services\CoverageService;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/** Coverage per region, so an admin can see where the money is not reaching. */
class CoverageByRegion extends BaseWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public function getTableHeading(): string
    {
        return __('sanabel.dashboard.coverage_by_region');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Beneficiary::published()->with('region'))
            ->columns([
                Tables\Columns\TextColumn::make('file_number')->label(__('sanabel.beneficiary.file_number'))->searchable(),
                Tables\Columns\TextColumn::make('region.name_ar')->label(__('sanabel.beneficiary.region'))->sortable(),
                Tables\Columns\TextColumn::make('support_type')
                    ->label(__('sanabel.beneficiary.support_type'))
                    ->formatStateUsing(fn (string $state) => __('sanabel.masked.need_type.'.$state)),
                Tables\Columns\TextColumn::make('coverage')
                    ->label(__('sanabel.beneficiary.coverage'))
                    ->state(fn (Beneficiary $record) => app(CoverageService::class)->coveragePercent($record).'%')
                    ->badge()
                    ->color(fn (Beneficiary $record) => match (true) {
                        app(CoverageService::class)->coverageRatio($record) >= 1.0 => 'success',
                        app(CoverageService::class)->coverageRatio($record) > 0 => 'warning',
                        default => 'danger',
                    }),
                Tables\Columns\TextColumn::make('next_assessment_due_at')
                    ->label(__('sanabel.beneficiary.next_assessment'))
                    ->date()
                    ->color(fn ($state) => $state && $state->isPast() ? 'danger' : null),
            ])
            ->defaultPaginationPageOption(10);
    }
}
