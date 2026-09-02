<?php

namespace App\Policies\Concerns;

use App\Models\User;
use App\Services\PermissionService;

/**
 * Hard rule 1 — council is denied every write route, explicitly, in every Policy.
 */
trait DeniesReadOnlyRoles
{
    protected function permissions(): PermissionService
    {
        return app(PermissionService::class);
    }

    protected function canWrite(User $user, string $permissionKey): bool
    {
        if ($user->isReadOnly()) {
            return false;
        }

        return $this->permissions()->has($user, $permissionKey);
    }

    protected function canRead(User $user, string $permissionKey): bool
    {
        return $this->permissions()->has($user, $permissionKey);
    }
}
