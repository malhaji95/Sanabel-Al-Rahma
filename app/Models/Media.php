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
        'scan_status', 'scanned_at', 'scan_note', 'uploaded_by', 'created_by',
    ];

    /*
     | T-58 (FILE-01) — where an upload stands.
     |
     |   clean    passed every check the system can run, and may be served
     |   pending  stored, waiting on a scanner outside the system
     |   rejected failed a check; kept, because rule 3 forbids erasing anything
     */
    public const CLEAN = 'clean';

    public const PENDING = 'pending';

    public const REJECTED = 'rejected';

    protected function casts(): array
    {
        return ['scanned_at' => 'datetime'];
    }

    /**
     * What a family's file may carry. A controlled list rather than free
     * text, so the papers can be looked for later and a kind cannot quietly
     * become a second description of the household.
     */
    public const FAMILY_KINDS = [
        'identity', 'residence', 'lease', 'medical', 'debt', 'income', 'other',
    ];

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function owner()
    {
        return $this->morphTo(__FUNCTION__, 'owner_type', 'owner_id');
    }

    /** Only a file that has cleared its checks is ever handed out. */
    public function isServable(): bool
    {
        return $this->scan_status === self::CLEAN;
    }

    /** Private bucket only — there is no public media URL. */
    public function signedUrl(int $minutes = 5): string
    {
        if (! $this->isServable()) {
            throw new \RuntimeException(__('sanabel.document.not_cleared'));
        }

        return Storage::disk(config('sanabel.media_disk'))
            ->temporaryUrl($this->storage_key, now()->addMinutes($minutes));
    }
}
