<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

trait TracksCreator
{
    public static function bootTracksCreator(): void
    {
        static::creating(function (Model $m) {
            if (Auth::check() && $m->isFillable('created_by') !== null && $m->getAttribute('created_by') === null) {
                $m->setAttribute('created_by', Auth::id());
            }
        });
    }
}
