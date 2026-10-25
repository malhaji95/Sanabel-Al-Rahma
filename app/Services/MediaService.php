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
 *
 * T-58 (FILE-01) added the gate on the way in. Before it, the stored
 * extension came from the name the browser sent and nothing checked the type
 * or the size at all, so a file called paper.php was stored as paper.php.
 */
class MediaService
{
    /**
     * T-123 — the uploader may be nobody.
     *
     * A donor giving without an account uploads the slip for their own
     * transfer, and there is no user to name. The row still records what was
     * uploaded, when, and against which donation; it simply cannot name a
     * person who was never asked to sign in.
     */
    public function store(UploadedFile $file, Model $owner, string $kind, ?User $uploader = null): Media
    {
        $disk = config('sanabel.media_disk');
        $config = config('sanabel.media');

        /*
         | The type the file actually is, sniffed from its content, never the
         | one its name claims. The extension comes from this map, so an
         | attacker-chosen filename cannot decide what lands on the disk.
         */
        $mime = (string) $file->getMimeType();

        if (! isset($config['allowed'][$mime])) {
            throw new \RuntimeException(__('sanabel.document.type_refused'));
        }

        $size = (int) $file->getSize();

        if ($size > $config['max_bytes']) {
            throw new \RuntimeException(__('sanabel.document.too_large', [
                'limit' => (int) round($config['max_bytes'] / 1024 / 1024),
            ]));
        }

        // Keyed by owner, never by the family's name or national ID.
        $path = sprintf(
            '%s/%d/%s.%s',
            str_replace('\\', '_', $owner::class),
            $owner->getKey(),
            bin2hex(random_bytes(16)),
            $config['allowed'][$mime],
        );

        Storage::disk($disk)->put($path, $file->get(), 'private');

        /*
         | Clean means it passed every check this system can run. It is not a
         | claim that an antivirus looked at it: FILE-02 needs a scanner the
         | association provides, and until they do, turning on
         | require_external_scan holds every new upload instead of serving it.
         */
        $held = (bool) $config['require_external_scan'];

        return Media::create([
            'owner_type' => $owner::class,
            'owner_id' => $owner->getKey(),
            'kind' => $kind,
            'storage_key' => $path,
            'visibility' => 'internal',
            'mime' => $mime,
            'size_bytes' => $size,
            'scan_status' => $held ? Media::PENDING : Media::CLEAN,
            'scanned_at' => $held ? null : now(),
            'uploaded_by' => $uploader?->getKey(),
        ]);
    }

    /** Short-lived by design; the default is five minutes. */
    public function temporaryUrl(Media $media, int $minutes = 5): string
    {
        // Nothing that has not cleared its checks is ever served, whoever asks.
        if (! $media->isServable()) {
            throw new \RuntimeException(__('sanabel.document.not_cleared'));
        }

        return Storage::disk(config('sanabel.media_disk'))
            ->temporaryUrl($media->storage_key, now()->addMinutes($minutes));
    }

    /** Rule 3 — nothing is hard-deleted, the object included. */
    public function softDelete(Media $media): void
    {
        $media->delete();
    }
}
