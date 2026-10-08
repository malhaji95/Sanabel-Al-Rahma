<?php

namespace App\Filament\Pages;

use App\Models\Permission;
use App\Models\Role;
use App\Services\PermissionService;
use Filament\Pages\Page;

/**
 * Every role beside every permission, read from the rows themselves.
 *
 * The association asked to see the matrix rather than the role names, so this
 * page builds it from the same table the policies read. Nothing is written
 * here: it is the matrix as it actually is, not a second copy of it that
 * could drift.
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
        $roles = Role::with('permissions')->orderBy('id')->get();

        $permissions = Permission::query()->get()->keyBy('key');

        $groups = [
            'write' => collect(PermissionService::WRITE_PERMISSIONS)
                ->map(fn (string $key) => $permissions[$key] ?? null)->filter()->values()->all(),
            'read' => collect(PermissionService::READ_PERMISSIONS)
                ->map(fn (string $key) => $permissions[$key] ?? null)->filter()->values()->all(),
        ];

        return ['roles' => $roles, 'groups' => $groups];
    }

    /** What the role really holds: the pivot, after the read-only rule. */
    public function grant(Role $role, Permission $permission): ?string
    {
        if ($role->is_read_only && in_array($permission->key, PermissionService::WRITE_PERMISSIONS, true)) {
            return null;
        }

        return $role->permissions->firstWhere('key', $permission->key)?->pivot?->scope;
    }
}
