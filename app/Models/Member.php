<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TracksCreator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Member extends Model
{
    use Auditable, HasFactory, SoftDeletes, TracksCreator;

    protected $fillable = ['user_id', 'membership_no', 'name_ar', 'category', 'status', 'joined_at', 'created_by'];

    protected $casts = ['joined_at' => 'date'];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
