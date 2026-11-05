<?php

namespace App\Livewire;

use App\Models\Complaint;
use App\Models\Page;
use App\Models\Setting;
use App\Services\ComplaintService;
use App\Services\IdentityFormat;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * T-258 — «تواصل معنا», the desk their mock-up of 5 November drew.
 *
 * Three doors on one screen, as they drew them: open a ticket, follow one,
 * or send a confidential report. T-257 built what sits under this; the
 * screen is the half a visitor touches.
 *
 * What their page says and this keeps:
 *
 *   · «متى أحصل على رقم التذكرة؟ … يظهر رقم حقيقي بعد حفظ الطلب على
 *     الخادم، وليس بمجرد النقر على زر الإرسال» — so the reference is read
 *     off the row that was written, never composed here;
 *   · the confidential path warns before it is used, and the warning is
 *     theirs word for word;
 *   · following a ticket shows its state and nothing of the desk: no
 *     internal note, no owner, no attachment, nothing of an inquiry.
 *
 * The official channels are settings, and an empty one is not drawn —
 * «يُضاف بعد الاعتماد الرسمي — لن نعرض رقمًا افتراضيًا» is their own
 * instruction and the reason this screen can ship before they have
 * decided on an address.
 */
class ContactDesk extends Component
{
    use WithFileUploads;

    public string $door = 'new';

    // The ticket.
    public string $name = '';

    public string $phone = '';

    public string $email = '';

    public string $role = '';

    public string $category = '';

    public string $subject = '';

    public string $body = '';

    public $attachment = null;

    public bool $consent = false;

    // What comes back.
    public ?string $reference = null;

    public ?string $error = null;

    /** Following a ticket. */
    public string $trackReference = '';

    public string $trackContact = '';

    /** @var array<string, mixed>|null */
    public ?array $tracked = null;

    public function mount(?string $door = null): void
    {
        if (in_array($door, ['new', 'track', 'secret'], true)) {
            $this->openDoor($door);
        }
    }

    public function openDoor(string $door): void
    {
        $this->door = in_array($door, ['new', 'track', 'secret'], true) ? $door : 'new';
        $this->error = null;

        // The confidential door is the same form with its kind already
        // chosen, which is how their own mock-up behaves.
        if ($this->door === 'secret') {
            $this->category = Complaint::CONFIDENTIAL_CATEGORY;
        }
    }

    public function file(): void
    {
        $this->error = $this->reference = null;

        $this->validate([
            'name' => ['required', 'string', 'min:2', 'max:90'],
            'phone' => ['nullable', 'string', 'digits:'.IdentityFormat::PHONE_DIGITS],
            'email' => ['nullable', 'email', 'max:160'],
            'role' => ['required', 'string'],
            'category' => ['required', 'string'],
            'subject' => ['required', 'string', 'min:4', 'max:120'],
            'body' => ['required', 'string', 'min:10', 'max:4000'],
            'attachment' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            'consent' => ['accepted'],
        ], attributes: [
            'name' => __('sanabel.contact.name'),
            'phone' => __('sanabel.contact.phone'),
            'email' => __('sanabel.contact.email'),
            'role' => __('sanabel.contact.role'),
            'category' => __('sanabel.contact.kind'),
            'subject' => __('sanabel.contact.subject'),
            'body' => __('sanabel.contact.body'),
            'attachment' => __('sanabel.contact.attachment'),
            'consent' => __('sanabel.contact.consent_label'),
        ]);

        // The page is open to anybody, so the one thing worth limiting is
        // how fast the desk can be filled with tickets nobody wrote.
        if (RateLimiter::tooManyAttempts($key = 'contact:'.request()->ip(), 6)) {
            $this->error = __('sanabel.contact.too_many');

            return;
        }

        RateLimiter::hit($key, 3600);

        try {
            $ticket = app(ComplaintService::class)->file([
                'subject_ar' => $this->subject,
                'body_ar' => $this->body,
                'category' => $this->category,
                'reporter_name_ar' => $this->name,
                'reporter_phone' => $this->phone,
                'reporter_email' => $this->email,
                'reporter_role' => $this->role,
            ], $this->attachment instanceof TemporaryUploadedFile ? $this->attachment : null,
                auth()->user());
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();

            return;
        }

        // Read off the row that was written, never composed here.
        $this->reference = $ticket->reference_no;

        $this->reset(['subject', 'body', 'attachment', 'consent']);
    }

    public function track(): void
    {
        $this->error = null;
        $this->tracked = null;

        // Looking a ticket up is being on that door, wherever the call came
        // from: a result drawn on a screen showing the other form is a
        // result nobody sees.
        $this->door = 'track';

        if (RateLimiter::tooManyAttempts($key = 'contact-track:'.request()->ip(), 10)) {
            $this->error = __('sanabel.contact.too_many');

            return;
        }

        RateLimiter::hit($key, 900);

        $ticket = app(ComplaintService::class)->track($this->trackReference, $this->trackContact);

        if (! $ticket) {
            // The same answer for a reference that does not exist and one
            // whose contact does not match, so this page cannot be used to
            // discover which references are real.
            $this->error = __('sanabel.contact.not_found');

            return;
        }

        $this->tracked = app(ComplaintService::class)->publicView($ticket);
    }

    /** The association's own channels. An empty one is not drawn. */
    public function channels(): array
    {
        return collect(['association_email', 'association_phone', 'association_social'])
            ->mapWithKeys(fn (string $key) => [$key => trim((string) Setting::value(
                $key, config('sanabel.setting_defaults.'.$key)
            ))])
            ->filter(fn (string $value) => $value !== '')
            ->all();
    }

    public function render()
    {
        return view('livewire.contact-desk', [
            'categories' => __('sanabel.complaint_category'),
            'roles' => __('sanabel.complaint_role'),
            'channels' => $this->channels(),
            // T-122 — the words under the desk are rows the association edits.
            'page' => Page::where('slug', 'contact')->where('is_published', true)->first(),
        ])->layout('layouts.app');
    }
}
