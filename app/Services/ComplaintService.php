<?php

namespace App\Services;

use App\Models\Complaint;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * T-257 — the contact desk, as the association drew it on 5 November.
 *
 * One place where a ticket is opened, whoever opens it: a visitor with no
 * account, a donor who is signed in, or the family portal when a household
 * disputes a transfer. Before this there were two of them and they wrote
 * different words into the same columns.
 *
 * Three rules the page itself states and this enforces:
 *
 *   · «يجب أن يفرض الخادم فصلًا في الصلاحيات يمنع الدعم العام من قراءة
 *     محتوى البلاغ أو مرفقاته» — so confidentiality is a column and a
 *     query, not a hidden field on a screen;
 *   · the reference is issued when the row is written and not when a
 *     button is pressed, which is their own answer to «متى أحصل على رقم
 *     التذكرة؟»;
 *   · following a ticket needs the reference AND the contact it was
 *     opened with. Their page says a one-time code belongs here in
 *     production; until the association has a way to send one, the second
 *     factor is the contact itself, and the attempt is rate-limited where
 *     it is made.
 */
class ComplaintService
{
    /**
     * Opens a ticket.
     *
     * @param  array<string, mixed>  $data
     */
    public function file(array $data, ?UploadedFile $attachment = null, ?User $actor = null): Complaint
    {
        $subject = trim((string) ($data['subject_ar'] ?? ''));
        $body = trim((string) ($data['body_ar'] ?? ''));
        $category = (string) ($data['category'] ?? 'other');

        if ($subject === '' || $body === '') {
            throw new \RuntimeException(__('sanabel.complaints.subject_and_body_required'));
        }

        if (! in_array($category, Complaint::CATEGORIES, true)) {
            throw new \RuntimeException(__('sanabel.complaints.unknown_category'));
        }

        $phone = trim((string) ($data['reporter_phone'] ?? ''));
        $email = trim((string) ($data['reporter_email'] ?? ''));

        // Somebody has to be reachable, or the ticket is a note nobody can
        // answer. A signed-in reporter is reachable through their account.
        if (! $actor && $phone === '' && $email === '') {
            throw new \RuntimeException(__('sanabel.complaints.contact_required'));
        }

        return DB::transaction(function () use ($data, $subject, $body, $category, $phone, $email, $attachment, $actor) {
            $complaint = Complaint::create([
                'reference_no' => Complaint::nextReference(),
                'submitted_by' => $actor?->getKey(),
                'subject_ar' => $subject,
                'body_ar' => $body,
                'category' => $category,
                'status' => 'new',
                'reporter_name_ar' => filled($data['reporter_name_ar'] ?? null)
                    ? trim((string) $data['reporter_name_ar'])
                    : $actor?->name,
                'reporter_phone_encrypted' => $phone !== '' ? $phone : null,
                'reporter_phone_hash' => $phone !== '' ? Complaint::hashContact($phone) : null,
                'reporter_email' => $email !== '' ? $email : null,
                'reporter_role' => $data['reporter_role'] ?? null,
            ]);

            if ($attachment) {
                /*
                 | The same path a family's documents take: sniffed, size
                 | limited, and written to a private disk that is only ever
                 | served through a short-lived signed URL. Their page asked
                 | for exactly this and it was already built.
                 */
                $media = app(MediaService::class)->store($attachment, $complaint, 'complaint', $actor);

                $complaint->forceFill(['attachment_media_id' => $media->getKey()])->save();
            }

            return $complaint->refresh();
        });
    }

    /**
     * Finds a ticket for somebody following it: the reference, and the
     * contact it was opened with. Either one alone finds nothing.
     */
    public function track(string $reference, string $contact): ?Complaint
    {
        $reference = mb_strtoupper(trim($reference));
        $contact = trim($contact);

        if ($reference === '' || $contact === '') {
            return null;
        }

        return Complaint::query()
            ->where('reference_no', $reference)
            ->where(fn ($q) => $q
                ->where('reporter_phone_hash', Complaint::hashContact($contact))
                ->orWhereRaw('lower(reporter_email) = ?', [mb_strtolower($contact)]))
            ->first();
    }

    /** What a ticket's own reporter is allowed to read back about it. */
    public function publicView(Complaint $complaint): array
    {
        return [
            'reference_no' => $complaint->reference_no,
            'status' => $complaint->status,
            'status_label' => __('sanabel.complaint_status.'.$complaint->status),
            'subject_ar' => $complaint->subject_ar,
            'created_at' => $complaint->created_at,
            'is_open' => in_array($complaint->status, Complaint::OPEN_STATUSES, true),
            // Never: the internal note, the owner's name, who it is about,
            // the attachment, or anything of an investigation. Their own
            // page says so and it is the whole point of a public view.
        ];
    }
}
