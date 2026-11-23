<?php

use App\Livewire\ContactDesk;
use App\Models\Complaint;
use App\Services\ComplaintService;

/*
 | T-319 — phase two, item 3: «استئناف» و«محقّق مستقلّ».
 |
 | A complaint could be opened, followed and settled, and a settlement was the
 | end of the road. The only way back was a second complaint about the same
 | thing, which arrives as a fresh ticket carrying none of the first one's
 | history — so the desk reading it cannot see that this is the second time of
 | asking.
 |
 | An appeal keeps the reference and the history, reopens the ticket, and takes
 | it off the desk that settled it.
 */

beforeEach(function () {
    seedCore();
    $this->service = app(ComplaintService::class);
    $this->owner = userWithRole('case_officer');
});

/** A ticket from a visitor, settled by somebody. */
function settledTicket(array $overrides = []): Complaint
{
    $ticket = test()->service->file(array_merge([
        'subject_ar' => 'لم تصلني المساعدة الشهرية',
        'body_ar' => 'مضى شهران ولم يصل شيء رغم الوعد.',
        'category' => 'payment',
        'reporter_name_ar' => 'زائر',
        'reporter_phone' => '0912345678',
        'reporter_role' => 'visitor',
    ], $overrides));

    $ticket->forceFill([
        'owner_id' => test()->owner->id,
        'status' => 'resolved',
        'resolution_ar' => 'تمّ التحويل.',
    ])->save();

    return $ticket->refresh();
}

it('reopens the ticket on the same reference, and takes it off the desk that settled it', function () {
    $ticket = settledTicket();
    $reference = $ticket->reference_no;

    $this->service->appeal($ticket, 'التحويل لم يصلني, والرقم الذي أعطيتموني ليس حسابي.');

    expect($ticket->reference_no)->toBe($reference)
        ->and($ticket->status)->toBe('new')
        ->and($ticket->owner_id)->toBeNull()
        ->and($ticket->appealed_at)->not->toBeNull()
        ->and($ticket->appealed_from_owner_id)->toBe($this->owner->id)
        ->and($ticket->appeal_reason_ar)->toContain('ليس حسابي')
        // The first answer stays on the record: the appeal is against it, so
        // erasing it would erase what is being appealed.
        ->and($ticket->resolution_ar)->toBe('تمّ التحويل.');
});

it('refuses to hand an appealed ticket back to the desk that answered it', function () {
    $ticket = settledTicket();

    $this->service->appeal($ticket, 'لم أُنصَف.');

    expect(fn () => $ticket->forceFill(['owner_id' => $this->owner->id])->save())
        ->toThrow(RuntimeException::class, __('sanabel.complaints.appeal_same_owner'));

    // And any other desk may take it.
    $other = userWithRole('data_supervisor');

    $ticket->forceFill(['owner_id' => $other->id, 'status' => 'assigned'])->save();

    expect($ticket->refresh()->owner_id)->toBe($other->id);
});

it('hears an appeal once, and not twice on the same answer', function () {
    $ticket = settledTicket();

    $this->service->appeal($ticket, 'الأولى.');

    // Somebody settles it again.
    $ticket->forceFill(['status' => 'resolved', 'resolution_ar' => 'راجعناها.'])->save();

    expect($ticket->refresh()->mayBeAppealed())->toBeFalse();

    expect(fn () => $this->service->appeal($ticket, 'الثانية.'))
        ->toThrow(RuntimeException::class, __('sanabel.complaints.appeal_already_heard'));
});

it('refuses an appeal on a ticket nobody has answered yet', function () {
    $ticket = settledTicket();
    $ticket->forceFill(['status' => 'in_progress'])->save();

    expect(fn () => $this->service->appeal($ticket->refresh(), 'ما زلت أنتظر.'))
        ->toThrow(RuntimeException::class, __('sanabel.complaints.appeal_not_settled'));
});

it('refuses an appeal with nothing written in it', function () {
    $ticket = settledTicket();

    expect(fn () => $this->service->appeal($ticket, '   '))
        ->toThrow(RuntimeException::class, __('sanabel.complaints.appeal_reason_required'));

    expect($ticket->refresh()->appealed_at)->toBeNull()
        ->and($ticket->status)->toBe('resolved');
});

it('tells the reporter on their own page whether they may still appeal', function () {
    $ticket = settledTicket();

    $view = $this->service->publicView($ticket);

    expect($view['may_appeal'])->toBeTrue()
        ->and($view['appealed_at'])->toBeNull()
        // And still nothing of the desk (T-257).
        ->and($view)->not->toHaveKey('owner_id')
        ->and($view)->not->toHaveKey('resolution_ar')
        ->and($view)->not->toHaveKey('appeal_reason_ar');

    $this->service->appeal($ticket, 'لم أُنصَف.');

    $view = $this->service->publicView($ticket->refresh());

    expect($view['may_appeal'])->toBeFalse()
        ->and($view['appealed_at'])->not->toBeNull();
});

it('lets the reporter appeal from the page they actually have', function () {
    /*
     | A visitor holds a reference and the contact they opened it with, and
     | nothing else. Both are checked again here: a ticket drawn on the screen
     | is not proof that the person at the keyboard is its reporter.
     */
    $ticket = settledTicket();

    Livewire\Livewire::test(ContactDesk::class)
        ->set('trackReference', $ticket->reference_no)
        ->set('trackContact', '0912345678')
        ->set('appealReason', 'الحساب الذي حوّلتم إليه ليس حسابي.')
        ->call('appeal')
        ->assertHasNoErrors();

    $ticket->refresh();

    expect($ticket->status)->toBe('new')
        ->and($ticket->owner_id)->toBeNull()
        ->and($ticket->appeal_reason_ar)->toContain('ليس حسابي');
});

it('refuses an appeal from somebody holding only the reference', function () {
    $ticket = settledTicket();

    Livewire\Livewire::test(ContactDesk::class)
        ->set('trackReference', $ticket->reference_no)
        ->set('trackContact', '0999999999')
        ->set('appealReason', 'لست صاحب الشكوى.')
        ->call('appeal');

    expect($ticket->refresh()->appealed_at)->toBeNull()
        ->and($ticket->status)->toBe('resolved');
})->group('AUTH-01');
