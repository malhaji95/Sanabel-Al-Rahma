<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TracksCreator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Media extends Model
{
    use Auditable, HasFactory, SoftDeletes, TracksCreator;

    protected $fillable = [
        'owner_type', 'owner_id', 'kind', 'storage_key', 'visibility', 'mime', 'size_bytes',
        'uploaded_by', 'created_by',
    ];

    public function owner()
    {
        return $this->morphTo(__FUNCTION__, 'owner_type', 'owner_id');
    }

    /** Private bucket only — there is no public media URL. */
    public function signedUrl(int $minutes = 5): string
    {
        return Storage::disk(config('sanabel.media_disk'))
            ->temporaryUrl($this->storage_key, now()->addMinutes($minutes));
    }
}
