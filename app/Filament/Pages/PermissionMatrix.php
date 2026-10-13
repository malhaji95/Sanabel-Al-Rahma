<?php

namespace App\Filament\Pages;

use App\Models\Permission;
use App\Models\Role;
use App\Services\PermissionService;
use Filament\Pages\Page;

/**
 * Every role beside every permission, read from the rows themselves.
 *
 * The association asked to see the matrix rather than the role names. Nothing
 * is written here: it is the matrix as it actually is, not a second copy of it
 * that could drift — and since T-92 the training guides print the same thing,
 * both of them asking PermissionService rather than keeping an answer of
 * their own.
 *
 * A read-only role (the board of trustees, the oversight committee) is shown
 * with every write struck out, because PermissionService denies those
 * whatever the pivot stores.
 */
class PermissionMatrix extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-table-cells';

    protected static string $view = 'filament.pages.permission-matrix';

    public static function getNavigationGroup(): ?string
    {
        return __('sanabel.nav.system');
    }

    public function getTitle(): string
    {
        return __('sanabel.matrix.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('sanabel.matrix.title');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        // Not everyone holding `view_reports`: almost every role holds it,
        // scoped to their own work, and this page is the whole matrix.
        return (bool) ($user?->can_('manage_users')
            || $user?->isAdmin()
            || $user?->hasRole('council', 'board_director', 'oversight_director'));
    }

    /** @return array{roles: \Illuminate\Support\Collection, groups: array<string,array<int,Permission>>} */
    public function getMatrix(): array
    {
        return app(PermissionService::class)->matrix();
    }

    /** What the role really holds: the pivot, after the read-only rule. */
    public function grant(Role $role, Permission $permission): ?string
    {
        return app(PermissionService::class)->grantedScope($role, $permission);
    }
}
