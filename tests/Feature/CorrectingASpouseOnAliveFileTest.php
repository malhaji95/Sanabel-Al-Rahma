<?php

use App\Models\ChangeRequest;
use App\Services\CaseService;

/*
 | T-218 — «٩ صح», 1 November, answering a gap we reported rather than one
 | the association found.
 |
 | T-172 added the spouse's work to the fields a change request may name and
 | left the spouse's own name and identity number off it, where they had
 | been since T-128. So on a live file a misspelt wife's name, or one wrong
 | digit in her identity number, could not be corrected at all: not by an
 | edit, because the edit screen is shut to a delegate once a file is
 | approved, and not by a request, because the allowlist refused the field.
 |
 | They go the same way as everything else here: one account raises the
 | correction and another approves it.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->officer = userWithRole('case_officer', ['region_id' => $this->region->id]);
    $this->approver = userWithRole('executive_director');
    $this->cases = app(CaseService::class);
});

/** An approved file with a spouse recorded on it. */
function fileWithASpouse($region)
{
    $case = publishedCase($region);

    $case->forceFill([
        'marital_status' => 'married',
        'spouse_name_ar' => 'فاطمه العلي',
        'spouse_national_id_encrypted' => '01234567890',
    ])->save();

    return $case->refresh();
}

it('lets a misspelt spouse name be raised as a correction', function () {
    $case = fileWithASpouse($this->region);

    $request = $this->cases->requestChange(
        $case,
        $this->officer,
        ['spouse_name_ar' => 'فاطمة العلي'],
        'خطأ إملائي في اسم الزوجة.',
    );

    expect($request)->toBeInstanceOf(ChangeRequest::class)
        ->and($request->status)->toBe('pending')
        // Nothing has moved on the file yet: a second hand decides.
        ->and($case->fresh()->spouse_name_ar)->toBe('فاطمه العلي');
});

it('lets a wrong digit in the spouse’s identity number be raised too', function () {
    $case = fileWithASpouse($this->region);

    $request = $this->cases->requestChange(
        $case,
        $this->officer,
        ['spouse_national_id_encrypted' => '01234567899'],
        'رقم وطني خاطئ.',
    );

    expect($request->status)->toBe('pending')
        ->and($case->fresh()->spouse_national_id_encrypted)->toBe('01234567890');
});

it('writes the correction onto the file once a second hand approves it', function () {
    $case = fileWithASpouse($this->region);

    $request = $this->cases->requestChange(
        $case,
        $this->officer,
        ['spouse_name_ar' => 'فاطمة العلي', 'spouse_national_id_encrypted' => '01234567899'],
        'تصحيح اسم الزوجة ورقمها.',
    );

    $this->cases->approveChange($request, $this->approver);

    expect($case->fresh()->spouse_name_ar)->toBe('فاطمة العلي')
        ->and($case->fresh()->spouse_national_id_encrypted)->toBe('01234567899');
});

it('still refuses everything the allowlist always refused', function () {
    $case = fileWithASpouse($this->region);

    // The two fields were added by name. Nothing else came with them.
    expect(fn () => $this->cases->requestChange(
        $case,
        $this->officer,
        ['spouse_name_ar' => 'اسم', 'status' => 'published'],
        'سبب',
    ))->toThrow(RuntimeException::class);

    expect(ChangeRequest::count())->toBe(0);
});

it('keeps the spouse’s number encrypted where the file holds it', function () {
    $case = fileWithASpouse($this->region);

    $this->cases->approveChange(
        $this->cases->requestChange(
            $case,
            $this->officer,
            ['spouse_national_id_encrypted' => '09999999999'],
            'تصحيح.',
        ),
        $this->approver,
    );

    // Read back through the model it decrypts; read off the row it does not.
    $raw = DB::table('beneficiaries')->where('id', $case->id)->value('spouse_national_id_encrypted');

    expect($case->fresh()->spouse_national_id_encrypted)->toBe('09999999999')
        ->and($raw)->not->toBe('09999999999');
});
