<?php

namespace App\Services;

use App\Models\Media;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * T-12 — media lives in a private bucket and is only ever reached through a
 * short-lived signed URL. There is no public media route, and media is never
 * shown to a donor (docs/07-decisions.md: internal by default).
 */
class MediaService
{
    public function store(UploadedFile $file, Model $owner, string $kind, User $uploader): Media
    {
        $disk = config('sanabel.media_disk');

        // Keyed by owner, never by the family's name or national ID.
        $path = sprintf(
            '%s/%d/%s.%s',
            str_replace('\\', '_', $owner::class),
            $owner->getKey(),
            bin2hex(random_bytes(16)),
            $file->getClientOriginalExtension() ?: 'bin',
        );

        Storage::disk($disk)->put($path, $file->get(), 'private');

        return Media::create([
            'owner_type' => $owner::class,
            'owner_id' => $owner->getKey(),
            'kind' => $kind,
            'storage_key' => $path,
            'visibility' => 'internal',
            'mime' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'uploaded_by' => $uploader->getKey(),
        ]);
    }

    /** Short-lived by design; the default is five minutes. */
    public function temporaryUrl(Media $media, int $minutes = 5): string
    {
        return Storage::disk(config('sanabel.media_disk'))
            ->temporaryUrl($media->storage_key, now()->addMinutes($minutes));
    }

    /** Rule 3 — nothing is hard-deleted, the object included. */
    public function softDelete(Media $media): void
    {
        $media->delete();
    }
}
