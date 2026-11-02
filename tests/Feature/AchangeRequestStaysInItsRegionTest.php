<?php

use App\Filament\Resources\BeneficiaryResource;
use App\Filament\Resources\ChangeRequestResource\Pages\ListChangeRequests;
use App\Models\ChangeRequest;
use App\Services\CaseService;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
 | T-209 — «المندوب يبحث فقط ضمن الملفات المسموح له بالاطلاع عليها في منطقته.
 | مندوب إزرع ما لازم يقدر يفتح ملف من بصرى الشام بمجرد معرفة رقمه. والبحث
 | لازم يطبق الصلاحيات على مستوى البيانات نفسها، وليس فقط إخفاء النتائج من
 | الشاشة», 1 November.
 |
 | The file itself has obeyed that since the first day, by a global scope on
 | the household — at the data, which is what they asked for — and T-223
 | wrote the sentence a delegate reads when they search for a file that is
 | not theirs. Going screen by screen after their message, one list was not
 | holding the rule: `change_requests` carries no region of its own, so a
 | delegate in one area was shown another area's requests, with the file
 | number, the area and what had been asked for on them.
 */

beforeEach(function () {
    seedCore();
    $this->here = regionWithRates();
    $this->elsewhere = regionWithRates();
    $this->cases = app(CaseService::class);
    $this->officer = userWithRole('case_officer');
    passTwoFactor();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

/** A pending request to correct a name on a file in this region. */
function requestOnAfileIn($region): ChangeRequest
{
    $case = publishedCase($region);

    return app(CaseService::class)->requestChange(
        $case,
        test()->officer,
        ['family_name' => 'العلي'],
        'خطأ إملائي في اسم العائلة.',
    );
}

/** The list as this account is served it. */
function changeRequestsFor($user)
{
    return Livewire::actingAs($user)->test(ListChangeRequests::class);
}

it('shows a delegate a request about a file in their own area', function () {
    $mine = requestOnAfileIn($this->here);
    $delegate = userWithRole('delegate', ['region_id' => $this->here->id]);

    changeRequestsFor($delegate)->assertCanSeeTableRecords([$mine]);
});

it('does not show them one about a file in another area', function () {
    $theirs = requestOnAfileIn($this->elsewhere);
    $delegate = userWithRole('delegate', ['region_id' => $this->here->id]);

    changeRequestsFor($delegate)->assertCanNotSeeTableRecords([$theirs]);
});

it('finds nothing when they search that file’s number', function () {
    $theirs = requestOnAfileIn($this->elsewhere);

    /*
     | Read before signing in as the delegate, and that is not a convenience:
     | once this account is the one asking, `$theirs->beneficiary` resolves to
     | null, because the rule lives on the household and not on the screen.
     | Which is the association's own sentence, demonstrated by accident.
     */
    $number = $theirs->beneficiary->file_number;

    $delegate = userWithRole('delegate', ['region_id' => $this->here->id]);

    /*
     | This half was already true before T-209: searching a related column
     | goes through `whereHas`, which carries the household's own scope. It
     | is kept as a guard rather than as the fix — the hole was the list
     | itself, which nobody had to search to read.
     */
    changeRequestsFor($delegate)
        ->searchTable($number)
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('holds the area supervisor to the same subtree', function () {
    $mine = requestOnAfileIn($this->here);
    $theirs = requestOnAfileIn($this->elsewhere);
    $supervisor = userWithRole('area_supervisor', ['region_id' => $this->here->id]);

    changeRequestsFor($supervisor)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('leaves an account that is not held to a region seeing both', function () {
    $here = requestOnAfileIn($this->here);
    $there = requestOnAfileIn($this->elsewhere);

    // The case officer reads the whole governorate, which is the job.
    changeRequestsFor($this->officer)->assertCanSeeTableRecords([$here, $there]);
});

it('keeps a request about a closed file in front of the people who read them', function () {
    $request = requestOnAfileIn($this->here);
    $request->beneficiary->delete();

    // Rule 3 — the file is soft-deleted, not gone, and the request about it
    // is still part of the record. The region filter is asked only of the
    // accounts the region binds, so this account's list is untouched.
    changeRequestsFor($this->officer)->assertCanSeeTableRecords([$request]);
});

it('refuses to open another area’s file by its address alone', function () {
    $theirs = publishedCase($this->elsewhere);
    $delegate = userWithRole('delegate', ['region_id' => $this->here->id]);

    // «ما لازم يقدر يفتح ملف من بصرى الشام بمجرد معرفة رقمه» — the record is
    // resolved through the scoped query, so the address resolves to nothing.
    $this->actingAs($delegate)
        ->get(BeneficiaryResource::getUrl('edit', ['record' => $theirs]))
        ->assertNotFound();
});
