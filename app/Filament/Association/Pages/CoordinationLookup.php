<?php

namespace App\Filament\Association\Pages;

use App\Services\DuplicateService;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;

/**
 * Hard rule 3 — an out-of-scope lookup returns four values and nothing else.
 * No name, no file number, no contact detail ever appears on this screen.
 */
class CoordinationLookup extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-magnifying-glass';

    protected static string $view = 'filament.association.pages.coordination-lookup';

    public ?array $data = [];

    /** @var array<string,mixed>|null */
    public ?array $result = null;

    public static function getNavigationLabel(): string
    {
        return __('sanabel.actions.lookup');
    }

    public function getTitle(): string
    {
        return __('sanabel.actions.lookup');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can_('search_by_national_id') ?? false;
    }

    public function form(Forms\Form $form): Forms\Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('national_id')
                    ->label(__('sanabel.beneficiary.national_id'))
                    ->required()
                    ->maxLength(64),
            ])
            ->statePath('data');
    }

    public function lookup(): void
    {
        abort_unless(auth()->user()->can_('search_by_national_id'), 403);

        $this->result = app(DuplicateService::class)
            ->coordinationLookup($this->form->getState()['national_id']);
    }
}
