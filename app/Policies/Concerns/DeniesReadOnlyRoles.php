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

    /**
     * T-115 — who may open a screen at all.
     *
     * It used to be `view_reports`, copied into twenty-five policies. Nearly
     * every role holds that key — a delegate holds it, a donor holds it, a
     * partner association holds it — so it had quietly become a master key to
     * the whole panel. Measured on 20 October: the area supervisor was shown
     * twenty-one screens and could act on one, the accountant twenty-one and
     * could create in none. A screen somebody can only look at teaches them
     * to stop reading screens.
     *
     * Reading a list is not a report, so the key to a screen is now the key
     * to the thing on it: you may open it if you may do something with what
     * is there. Reports keep `view_reports`, which is what it was named for.
     *
     * Beside that, the roles whose job is to look. The board of trustees is
     * read-only by hard rule 1 and exists to oversee; the chairman and his
     * deputy hold every key anyway; and the administrator keeps the reading
     * it has always had, because T-105 took its decisions away and left its
     * reads alone deliberately.
     *
     * T-202 — «يجب على المدير التنفيذي رؤية كل شيء كي يستطيع متابعة عمل
     * الجميع لمعرفة في حال تأخر أي حدث في سير العملية», 1 November, after
     * signing in and finding no «التبرعات» in the menu.
     *
     * They were right, and the reason was this list. The executive director
     * holds the approvals — the file, the change, the campaign, the payment —
     * but not `verify_payment`, nor `donate`, nor `browse_job_market`; and
     * since T-115 the key to a screen is the key to the thing on it. So the
     * three screens where other people's work queues up were the three they
     * could not see.
     *
     * Joining the overseers opens the screens and opens nothing else: this
     * is read, and every write still goes through `canWrite()` with a key
     * they either hold or do not. Their own keys are untouched.
     */
    protected function oversees(User $user): bool
    {
        return $user->isAdmin()
            || $user->hasRole('council', 'board_director', 'deputy_board_director', 'executive_director');
    }

    /**
     * True when the user may act on what the screen holds, or oversees it.
     *
     * @param  string  ...$permissionKeys  the keys this policy itself uses
     */
    protected function opensScreen(User $user, string ...$permissionKeys): bool
    {
        foreach ($permissionKeys as $key) {
            if ($this->permissions()->has($user, $key)) {
                return true;
            }
        }

        return $this->oversees($user);
    }
}
