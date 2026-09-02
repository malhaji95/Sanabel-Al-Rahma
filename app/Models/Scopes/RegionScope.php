<?php

namespace App\Models\Scopes;

use App\Models\Region;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * One global scope for every model carrying region_id.
 * Roles with a region-limited scope only ever see their own subtree.
 */
class RegionScope implements Scope
{
    /** Roles whose visibility is limited to their own region subtree. */
    public const SCOPED_ROLES = ['delegate', 'area_supervisor', 'association'];

    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();

        if (! $user || ! $user->role) {
            return;
        }

        if (! in_array($user->role->key, self::SCOPED_ROLES, true)) {
            return;
        }

        if (! $user->region_id) {
            return;
        }

        $builder->whereIn(
            $model->getTable().'.region_id',
            Region::descendantIds($user->region_id)
        );
    }
}
