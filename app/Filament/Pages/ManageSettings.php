<?php

namespace App\Filament\Pages;

use App\Models\Role;
use App\Models\Setting;
use App\Services\AnonymiseService;
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

    /**
     * Who may move a switch. The administrator's own door belongs to the
     * accounts the association named as authorised, so the administrator
     * cannot open it for himself; the rest of the switches follow
     * `edit_config` like every other setting.
     */
    public static function maySwitch(string $key): bool
    {
        $user = auth()->user();

        if ($key === AnonymiseService::ADMIN_SWITCH) {
            return (bool) $user?->hasRole(...AnonymiseService::AUTHORISED_ROLES);
        }

        return (bool) $user?->can_('edit_config');
    }

    public function mount(): void
    {
        $wallet = Setting::value('platform_wallet', []);

        $this->form->fill(array_merge(
            collect(config('sanabel.setting_defaults'))
                ->mapWithKeys(fn ($default, $key) => [$key => Setting::value($key, $default)])
                ->all(),
            [
                'platform_wallet_number' => $wallet['number'] ?? null,
                'platform_wallet_holder' => $wallet['holder'] ?? null,
            ],
        ));
    }

    public function form(Forms\Form $form): Forms\Form
    {
        // Every numeric default draws itself. The routing settings are a mode
        // and two wallet fields, so they are written out rather than generated.
        $numeric = collect(config('sanabel.setting_defaults'))
            ->filter(fn ($default) => is_int($default))
            ->map(fn ($default, $key) => Forms\Components\TextInput::make($key)
                ->label(__('sanabel.settings.keys.'.$key))
                ->numeric()
                ->required())
            ->values()
            ->all();

        /*
         | T-75 — the switches. The administrator's door is one of them, and it
         | is shown only to the accounts that themselves hold the authority:
         | a door the administrator can open for himself is not a door.
         */
        $switches = collect(config('sanabel.setting_defaults'))
            ->filter(fn ($default) => is_bool($default))
            ->map(fn ($default, $key) => Forms\Components\Toggle::make($key)
                ->label(__('sanabel.settings.keys.'.$key))
                ->helperText(__('sanabel.settings.help.'.$key))
                ->visible(fn () => self::maySwitch($key))
                ->dehydrated(fn () => self::maySwitch($key)))
            ->values()
            ->all();

        return $form
            ->schema(array_merge($numeric, $switches, [
                Forms\Components\TextInput::make('platform_wallet_number')
                    ->label(__('sanabel.settings.keys.platform_wallet_number')),

                Forms\Components\TextInput::make('platform_wallet_holder')
                    ->label(__('sanabel.settings.keys.platform_wallet_holder')),

                /*
                 | T-95 — OPS-03. Who hears a security alert. Roles rather than
                 | addresses: an address in a settings row is a copy that goes
                 | stale the day someone leaves, and a role follows whoever
                 | holds it. Empty means nothing is sent, and the helper text
                 | says so rather than leaving it to be discovered.
                 */
                /*
                 | T-106 — the heading over the family's story. Four wordings
                 | the association offered on 16 October and chose none of,
                 | so the choice is theirs to make and remake here.
                 */
                Forms\Components\Select::make('story_title')
                    ->label(__('sanabel.settings.keys.story_title'))
                    ->helperText(__('sanabel.settings.help.story_title'))
                    ->options(__('sanabel.masked.story_titles'))
                    ->selectablePlaceholder(false),

                Forms\Components\Select::make('security_alert_roles')
                    ->label(__('sanabel.settings.keys.security_alert_roles'))
                    ->helperText(__('sanabel.settings.help.security_alert_roles'))
                    ->multiple()
                    ->options(fn () => Role::orderBy('id')->pluck('name_ar', 'key'))
                    ->columnSpanFull(),
            ]))
            ->columns(2)
            ->statePath('data');
    }

    public function save(): void
    {
        abort_unless(auth()->user()->can_('edit_config'), 403);

        $state = $this->form->getState();

        Setting::put('platform_wallet', [
            'number' => filled($state['platform_wallet_number'] ?? null)
                ? trim((string) $state['platform_wallet_number'])
                : null,
            'holder' => filled($state['platform_wallet_holder'] ?? null)
                ? trim((string) $state['platform_wallet_holder'])
                : null,
        ]);

        Setting::put('security_alert_roles', array_values(array_filter(
            (array) ($state['security_alert_roles'] ?? [])
        )));

        unset($state['platform_wallet_number'], $state['platform_wallet_holder'], $state['security_alert_roles']);

        $defaults = config('sanabel.setting_defaults');

        foreach ($state as $key => $value) {
            if (is_bool($defaults[$key] ?? null)) {
                abort_unless(self::maySwitch($key), 403);

                Setting::put($key, (bool) $value);

                continue;
            }

            /*
             | T-106 — a setting that is a word rather than a number. The
             | loop below used to cast everything that was not a switch to an
             | integer, which would have stored the story's heading as 0. The
             | value is held to the list it was offered from, so a hand-made
             | request cannot write a key that has no translation behind it.
             */
            if (is_string($defaults[$key] ?? null)) {
                $allowed = array_keys(__('sanabel.masked.story_titles'));

                Setting::put($key, in_array($value, $allowed, true) ? $value : $defaults[$key]);

                continue;
            }

            Setting::put($key, (int) $value);
        }

        Notification::make()->title(__('sanabel.settings.saved'))->success()->send();
    }
}
