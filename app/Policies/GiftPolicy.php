<?php

namespace App\Policies;

use App\Models\Gift;
use App\Models\User;
use App\Policies\Concerns\DeniesReadOnlyRoles;

/**
 * T-241 — a gift is money, so it is read by the accounts that read money.
 *
 * And it is never edited: what a donor wrote on a card is what the card
 * says, and the card has already gone out. A mistake is a conversation with
 * the donor, not a quiet correction behind them.
 */
class GiftPolicy
{
    use DeniesReadOnlyRoles;

    public function viewAny(User $user): bool
    {
        return $this->opensScreen($user, 'verify_donation', 'view_reports');
    }

    public function view(User $user, Gift $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Gift $model): bool
    {
        return false;
    }

    public function delete(User $user, Gift $model): bool
    {
        return false;
    }

    public function forceDelete(User $user, Gift $model): bool
    {
        return false;
    }
}
