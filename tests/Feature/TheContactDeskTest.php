<?php

/*
 | T-258 — «تواصل معنا», the desk their mock-up of 5 November drew: three
 | doors on one screen, a reference a visitor can follow, and a
 | confidential path the general desk cannot see.
 */

use App\Livewire\ContactDesk;
use App\Models\Complaint;
use App\Models\Setting;
use Database\Seeders\PageContentSeeder;
use Livewire\Livewire;

beforeEach(function () {
    seedCore();
    $this->seed(PageContentSeeder::class);
});

function filledTicket($component, array $overrides = [])
{
    $values = array_merge([
        'name' => 'زائر',
        'phone' => '0912345678',
        'email' => '',
        'role' => 'visitor',
        'category' => 'inquiry',
        'subject' => 'استفسار عن تسجيل الحالات',
        'body' => 'كيف تبدأ الأسرة تسجيلها لدى المؤسسة؟',
        'consent' => true,
    ], $overrides);

    foreach ($values as $key => $value) {
        $component->set($key, $value);
    }

    return $component;
}

it('opens with the three doors the association drew', function () {
    Livewire::test(ContactDesk::class)
        ->assertSuccessful()
        ->assertSee(__('sanabel.contact.door_new'))
        ->assertSee(__('sanabel.contact.door_track'))
        ->assertSee(__('sanabel.contact.door_secret'));
});

it('takes a ticket from a visitor and gives back a reference it can follow', function () {
    $component = filledTicket(Livewire::test(ContactDesk::class))
        ->call('file')
        ->assertHasNoErrors()
        ->assertSet('error', null);

    $ticket = Complaint::first();

    expect($ticket)->not->toBeNull()
        ->and($ticket->submitted_by)->toBeNull()
        ->and($ticket->status)->toBe('new');

    // Read off the row that was written, which is their own answer to
    // «متى أحصل على رقم التذكرة؟».
    $component->assertSet('reference', $ticket->reference_no)
        ->assertSee($ticket->reference_no);
});

it('asks for what it needs before it writes anything', function () {
    filledTicket(Livewire::test(ContactDesk::class), [
        'name' => '', 'body' => 'قصير', 'consent' => false, 'role' => '',
    ])
        ->call('file')
        ->assertHasErrors(['name', 'body', 'consent', 'role']);

    expect(Complaint::count())->toBe(0);
});

it('opens the confidential door with its kind already chosen and its warning shown', function () {
    Livewire::test(ContactDesk::class)
        ->call('openDoor', 'secret')
        ->assertSet('category', Complaint::CONFIDENTIAL_CATEGORY)
        ->assertSee(__('sanabel.contact.secret_notice'));
});

it('walls a confidential report off from the general desk', function () {
    filledTicket(Livewire::test(ContactDesk::class), [
        'category' => Complaint::CONFIDENTIAL_CATEGORY,
        'subject' => 'بلاغ رقابي',
    ])->call('file')->assertHasNoErrors();

    $report = Complaint::first();

    expect($report->is_confidential)->toBeTrue()
        // A desk without the reading key does not get the row at all.
        ->and(Complaint::query()->readableBy(userWithRole('case_officer'))->count())->toBe(0)
        ->and(Complaint::query()->readableBy(userWithRole('executive_director'))->count())->toBe(1);
});

it('follows a ticket with its reference and the contact it was opened with', function () {
    filledTicket(Livewire::test(ContactDesk::class))->call('file');

    $ticket = Complaint::first();

    Livewire::test(ContactDesk::class)
        ->call('openDoor', 'track')
        ->set('trackReference', $ticket->reference_no)
        ->set('trackContact', '0912345678')
        ->call('track')
        ->assertSet('error', null)
        ->assertSee($ticket->reference_no)
        ->assertSee(__('sanabel.complaint_status.new'));
});

it('answers a wrong reference and a wrong contact in exactly the same way', function () {
    filledTicket(Livewire::test(ContactDesk::class))->call('file');

    $ticket = Complaint::first();

    // So the page cannot be used to learn which references are real.
    $wrongContact = Livewire::test(ContactDesk::class)
        ->set('trackReference', $ticket->reference_no)
        ->set('trackContact', '0999999999')
        ->call('track');

    $wrongReference = Livewire::test(ContactDesk::class)
        ->set('trackReference', 'CMP-00-00000')
        ->set('trackContact', '0912345678')
        ->call('track');

    $wrongContact->assertSet('error', __('sanabel.contact.not_found'))->assertSet('tracked', null);
    $wrongReference->assertSet('error', __('sanabel.contact.not_found'))->assertSet('tracked', null);
});

it('shows the state of a ticket and nothing of the desk behind it', function () {
    filledTicket(Livewire::test(ContactDesk::class))->call('file');

    $ticket = Complaint::first();
    $ticket->forceFill([
        'status' => 'in_progress',
        'resolution_ar' => 'ملاحظة داخلية',
        'owner_id' => userWithRole('admin')->id,
    ])->save();

    Livewire::test(ContactDesk::class)
        ->set('trackReference', $ticket->reference_no)
        ->set('trackContact', '0912345678')
        ->call('track')
        ->assertSee(__('sanabel.complaint_status.in_progress'))
        ->assertDontSee('ملاحظة داخلية');
});

it('draws no channel the association has not adopted, and draws one it has', function () {
    Livewire::test(ContactDesk::class)
        ->assertSee(__('sanabel.contact.channels_pending'))
        ->assertDontSee(__('sanabel.settings.keys.association_phone'));

    Setting::put('association_email', 'info@sanabel.example');

    Livewire::test(ContactDesk::class)
        ->assertSee('info@sanabel.example')
        ->assertDontSee(__('sanabel.contact.channels_pending'));
});

it('draws the questions the association wrote under the desk', function () {
    $this->get('/contact')
        ->assertOk()
        ->assertSee(__('sanabel.contact.title'))
        ->assertSee('متى أحصل على رقم التذكرة؟')
        ->assertSee('هل يطّلع موظف الدعم على البلاغ الرقابي؟');
});

it('serves the desk at /contact rather than the content page of that name', function () {
    // The words are still theirs to edit; the desk is what the route draws.
    $this->get('/contact')
        ->assertOk()
        ->assertSee(__('sanabel.contact.door_new'))
        ->assertSee(__('sanabel.contact.door_secret'));
});
