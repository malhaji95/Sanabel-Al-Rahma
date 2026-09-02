<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TracksCreator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class JobRequest extends Model
{
    use Auditable, HasFactory, SoftDeletes, TracksCreator;

    protected $fillable = [
        'requester_name_ar', 'contact_encrypted', 'trade_key', 'region_id', 'job_profile_id',
        'description_ar', 'status', 'handled_by', 'created_by',
    ];

    protected $hidden = ['contact_encrypted'];

    protected function casts(): array
    {
        return ['contact_encrypted' => 'encrypted'];
    }
}
