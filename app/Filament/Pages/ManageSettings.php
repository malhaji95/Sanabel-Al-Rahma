<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Every operational default from docs/07-decisions.md lives in one table so it
 * changes without a deploy. This is the screen that changes it.
 */
class ManageSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static string $view = 'filament.pages.manage-settings';

    public ?array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return __('sanabel.nav.system');
    }

    public static function getNavigationLabel(): string
    {
        return __('sanabel.settings.title');
    }

    public function getTitle(): string
    {
        return __('sanabel.settings.title');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can_('edit_config') ?? false;
    }

    public function mount(): void
    {
        $this->form->fill(
            collect(config('sanabel.setting_defaults'))
                ->mapWithKeys(fn ($default, $key) => [$key => Setting::value($key, $default)])
                ->all()
        );
    }

    public function form(Forms\Form $form): Forms\Form
    {
        return $form
            ->schema(
                collect(config('sanabel.setting_defaults'))
                    ->map(fn ($default, $key) => Forms\Components\TextInput::make($key)
                        ->label(__('sanabel.settings.keys.'.$key))
                        ->numeric()
                        ->required())
                    ->values()
                    ->all()
            )
            ->columns(2)
            ->statePath('data');
    }

    public function save(): void
    {
        abort_unless(auth()->user()->can_('edit_config'), 403);

        foreach ($this->form->getState() as $key => $value) {
            Setting::put($key, (int) $value);
        }

        Notification::make()->title(__('sanabel.settings.saved'))->success()->send();
    }
}
