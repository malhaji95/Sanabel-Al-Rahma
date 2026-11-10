<?php

namespace App\Http\Controllers;

use App\Models\GovernanceDocument;
use App\Models\GovernanceDocumentVersion;
use App\Services\GovernanceAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * T-291 — the door a guarded document is opened through.
 *
 * Everywhere else in the system a file is handed out as a signed link
 * straight to the store: short-lived, private, and perfectly adequate for a
 * lease or an identity paper. It cannot serve here, for two reasons the
 * association's own conditions name. A signed link is checked once, when it
 * is made, and whoever holds it opens the file without passing this way
 * again — so «تسجيل فتح وتنزيل الوثائق السرية» would record the asking and
 * never the opening. And a link already issued does not notice that the
 * document has since been reclassified.
 *
 * So a confidential or secret document is read through the application: the
 * permission is checked at the moment of opening, the opening is written
 * down, and the bytes are streamed. The public and internal ones keep the
 * signed link, because logging every glance at the staff handbook would bury
 * the lines that matter.
 */
class GovernanceDocumentController extends Controller
{
    public function __construct(private readonly GovernanceAccess $access) {}

    public function show(Request $request, GovernanceDocumentVersion $version): StreamedResponse|RedirectResponse
    {
        $document = $version->document;
        $ability = $request->boolean('download') ? 'download' : 'view';

        // Checked and written down in one act, so no path exists that reads
        // without recording.
        abort_unless($this->access->check($request->user(), $document, $ability), 403);

        $media = $version->media;

        abort_if($media === null || ! $media->isServable(), 404);

        // Not guarded: the ordinary signed link, as everywhere else.
        if (! $document->isRestricted()) {
            return redirect()->away($media->signedUrl());
        }

        $disk = Storage::disk(config('sanabel.media_disk'));

        abort_unless($disk->exists($media->storage_key), 404);

        return $disk->download(
            $media->storage_key,
            $this->filename($document, $version, $media->storage_key),
            ['X-Robots-Tag' => 'noindex, nofollow'],
        );
    }

    /** A name that says what it is and which version, and nothing about anybody. */
    private function filename(GovernanceDocument $document, GovernanceDocumentVersion $version, string $key): string
    {
        $extension = pathinfo($key, PATHINFO_EXTENSION) ?: 'pdf';

        return $document->title_ar.' — '.__('sanabel.need_breakdown.version').' '.$version->version_no.'.'.$extension;
    }
}
