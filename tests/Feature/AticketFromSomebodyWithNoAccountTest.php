<?php

/*
 | T-257 — «تواصل معنا», the association's interactive mock-up of
 | 5 November.
 |
 | Their page is a ticket desk, not a page: a visitor with no account opens
 | a ticket and gets a reference, follows it with that reference and the
 | contact they left, and a separate path carries a confidential report
 | that general support must not be able to read — «يجب أن يفرض الخادم
 | فصلًا في الصلاحيات يمنع الدعم العام من قراءة محتوى البلاغ أو مرفقاته».
 */

use App\Models\AuditLog;
use App\Models\Complaint;
use App\Services\ComplaintService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    seedCore();
    $this->service = app(ComplaintService::class);
});

function visitorTicket(array $overrides = []): Complaint
{
    return test()->service->file(array_merge([
        'subject_ar' => 'استفسار عن طريقة تسجيل الحالات',
        'body_ar' => 'كيف تبدأ الأسرة تسجيلها لدى المؤسسة؟',
        'category' => 'inquiry',
        'reporter_name_ar' => 'زائر',
        'reporter_phone' => '0912345678',
        'reporter_role' => 'visitor',
    ], $overrides));
}

it('opens a ticket for somebody with no account, and gives it a reference', function () {
    $ticket = visitorTicket();

    expect($ticket->reference_no)->toMatch('/^CMP-\d{2}-\d{5}$/')
        // Nobody is signed in: this is the whole point of a contact page.
        ->and($ticket->submitted_by)->toBeNull()
        ->and($ticket->status)->toBe('new')
        ->and($ticket->reporter_name_ar)->toBe('زائر')
        ->and($ticket->is_confidential)->toBeFalse();
});

it('keeps the reporter’s number out of the table and out of the log', function () {
    $ticket = visitorTicket();

    $raw = DB::table('complaints')->where('id', $ticket->id)
        ->value('reporter_phone_encrypted');

    $entry = AuditLog::where('entity_type', Complaint::class)
        ->where('entity_id', $ticket->id)->first();

    // Rule 10 — encrypted at rest, read back by the model, redacted from
    // the log.
    expect($raw)->not->toContain('0912345678')
        ->and($ticket->refresh()->reporter_phone_encrypted)->toBe('0912345678')
        ->and(json_encode($entry?->after_json))->not->toContain('0912345678');
});

it('refuses a ticket nobody can be answered on', function () {
    expect(fn () => visitorTicket(['reporter_phone' => '', 'reporter_email' => '']))
        ->toThrow(RuntimeException::class, __('sanabel.complaints.contact_required'));

    expect(Complaint::count())->toBe(0);
});

it('refuses a ticket with no subject or no explanation', function () {
    expect(fn () => visitorTicket(['body_ar' => '  ']))
        ->toThrow(RuntimeException::class, __('sanabel.complaints.subject_and_body_required'));

    expect(fn () => visitorTicket(['category' => 'nonsense']))
        ->toThrow(RuntimeException::class, __('sanabel.complaints.unknown_category'));
});

it('finds a ticket by its reference and the contact it was opened with', function () {
    $ticket = visitorTicket();

    expect($this->service->track($ticket->reference_no, '0912345678')?->id)->toBe($ticket->id)
        // The number as it would be dialled from abroad finds the same row.
        ->and($this->service->track($ticket->reference_no, '+963 912 345 678')?->id)->toBe($ticket->id);
});

it('finds nothing with a reference alone, or with the wrong contact', function () {
    $ticket = visitorTicket();

    expect($this->service->track($ticket->reference_no, ''))->toBeNull()
        ->and($this->service->track($ticket->reference_no, '0999999999'))->toBeNull()
        ->and($this->service->track('CMP-26-99999', '0912345678'))->toBeNull();
});

it('finds a ticket by the address it was opened with', function () {
    $ticket = visitorTicket(['reporter_phone' => '', 'reporter_email' => 'Visitor@Example.com']);

    expect($this->service->track($ticket->reference_no, 'visitor@example.com')?->id)->toBe($ticket->id);
});

it('tells the reporter the state of their ticket and nothing of the desk', function () {
    $ticket = visitorTicket();

    $ticket->forceFill([
        'status' => 'in_progress',
        'resolution_ar' => 'ملاحظة داخلية لا تخص مقدّم الطلب',
        'owner_id' => userWithRole('admin')->id,
    ])->save();

    $view = $this->service->publicView($ticket->refresh());

    expect($view['status'])->toBe('in_progress')
        ->and($view['status_label'])->toBe('قيد المعالجة')
        ->and($view['is_open'])->toBeTrue()
        // Never the internal note, the owner, or anything of an inquiry.
        ->and(json_encode($view))->not->toContain('ملاحظة داخلية')
        ->and(array_keys($view))->not->toContain('resolution_ar', 'owner_id', 'body_ar');
});

it('turns the confidential kind into the wall itself', function () {
    $report = visitorTicket(['category' => 'confidential', 'subject_ar' => 'بلاغ رقابي']);

    // Choosing the kind is choosing the separation: not a field a screen
    // sets, a column the model writes.
    expect($report->is_confidential)->toBeTrue();
});

it('keeps a confidential report away from the general desk', function () {
    visitorTicket();
    visitorTicket(['category' => 'confidential', 'subject_ar' => 'بلاغ رقابي']);

    $support = userWithRole('case_officer');
    $director = userWithRole('executive_director');
    $oversight = userWithRole('oversight_director');

    $readable = fn ($user) => Complaint::query()->readableBy($user)->pluck('category')->all();

    // The general desk does not get the row at all: nothing to count,
    // nothing to search, nothing to read in a screenshot.
    expect($readable($support))->toBe(['inquiry'])
        ->and($readable($director))->toContain('confidential')
        // The oversight committee reads them — that is why the path exists.
        ->and($readable($oversight))->toContain('confidential');
});

it('lets the oversight committee read a report and never write one', function () {
    visitorTicket(['category' => 'confidential']);

    $oversight = userWithRole('oversight_director');
    $director = userWithRole('executive_director');

    /*
     | Two keys, because they are two different acts. Reading is not a
     | write, so the oversight committee holds it despite being a read-only
     | role; handling is a write, so they do not hold it and `is_read_only`
     | would refuse it even if the matrix were edited to grant it.
     */
    expect($oversight->can_('read_confidential_complaint'))->toBeTrue()
        ->and($oversight->can_('handle_confidential_complaint'))->toBeFalse()
        ->and($director->can_('read_confidential_complaint'))->toBeTrue()
        ->and($director->can_('handle_confidential_complaint'))->toBeTrue();
});

it('stores an attachment the way a family’s documents are stored', function () {
    Storage::fake('media_local');

    $ticket = $this->service->file([
        'subject_ar' => 'مرفق تجريبي',
        'body_ar' => 'التفاصيل في المرفق.',
        'category' => 'inquiry',
        'reporter_name_ar' => 'زائر',
        'reporter_phone' => '0912345678',
    ], UploadedFile::fake()->image('receipt.jpg', 600, 600));

    expect($ticket->attachment_media_id)->not->toBeNull()
        ->and($ticket->attachment)->not->toBeNull()
        // The private disk, never the public one: it is served only through
        // a short-lived signed URL, like every other document here.
        ->and($ticket->attachment->disk)->not->toBe('public');
});

it('writes a disputed transfer into the same desk, with a status that has a word', function () {
    $ticket = visitorTicket(['category' => 'payment', 'subject_ar' => 'لم يصلني المبلغ']);

    // The old path wrote `financial` and `open`, and neither is in either
    // list, so the panel drew `sanabel.complaint_status.open` in English.
    expect(in_array($ticket->category, Complaint::CATEGORIES, true))->toBeTrue()
        ->and(in_array($ticket->status, Complaint::STATUSES, true))->toBeTrue()
        ->and(__('sanabel.complaint_status.'.$ticket->status))->not->toContain('sanabel.');
});
