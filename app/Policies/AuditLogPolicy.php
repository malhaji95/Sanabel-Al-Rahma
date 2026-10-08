<?php

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;
use App\Policies\Concerns\DeniesReadOnlyRoles;

/**
 * The log is append-only, so this policy reads and nothing else. The model
 * itself throws on update and delete; these just keep the panel honest.
 */
class AuditLogPolicy
{
    use DeniesReadOnlyRoles;

    /**
     * Deliberately narrow. `view_reports` is held by almost every role, often
     * scoped to their own work, and the log is not a report: it names who did
     * what to which family's file. It is for the accounts that oversee.
     */
    public function viewAny(User $user): bool
    {
        return $this->canWrite($user, 'manage_users')
            || $user->isAdmin()
            || $user->hasRole('council', 'board_director', 'oversight_director');
    }

    public function view(User $user, AuditLog $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, AuditLog $model): bool
    {
        return false;
    }

    public function delete(User $user, AuditLog $model): bool
    {
        return false;
    }

    public function forceDelete(User $user, AuditLog $model): bool
    {
        return false;
    }
}
