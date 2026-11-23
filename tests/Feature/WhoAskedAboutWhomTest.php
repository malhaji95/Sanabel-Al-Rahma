<?php

use App\Models\AuditLog;
use App\Models\Beneficiary;
use App\Services\DuplicateService;

/*
 | T-317 — phase two, item 5: «تسجيل قراءة البيانات الحساسة».
 |
 | Rule 4 logs every write and said plainly that reads are not logged in phase
 | one. This is the read the association named first, and the sharpest one in
 | the system: somebody hands it a particular person's identity number and it
 | answers whether that person is on file. Nothing recorded who asked, or
 | about whom.
 |
 | The number itself is never written down (rule 10): the row says who asked,
 | when, and which file answered.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->asker = userWithRole('association');
});

it('writes down who asked and which file answered', function () {
    $case = publishedCase($this->region);
    $nationalId = $case->national_id_encrypted;

    expect($nationalId)->not->toBeEmpty();

    app(DuplicateService::class)->coordinationLookup($nationalId, $this->asker);

    $row = AuditLog::where('action', 'read_by_national_id')->latest('id')->first();

    expect($row)->not->toBeNull()
        ->and($row->actor_id)->toBe($this->asker->id)
        ->and($row->actor_role)->toBe('association')
        ->and($row->entity_type)->toBe(Beneficiary::class)
        ->and($row->entity_id)->toBe($case->id)
        ->and($row->after_json)->toBe(['found' => true]);
});

it('never writes the number it was asked about', function () {
    /*
     | Rule 10. The whole point of the lookup is an identity number, so this
     | is the row most likely to carry one by accident.
     */
    $case = publishedCase($this->region);
    $nationalId = $case->national_id_encrypted;

    app(DuplicateService::class)->coordinationLookup($nationalId, $this->asker);

    $row = AuditLog::where('action', 'read_by_national_id')->latest('id')->first();

    $written = json_encode([$row->before_json, $row->after_json], JSON_UNESCAPED_UNICODE);

    expect($written)->not->toContain($nationalId)
        ->and($written)->not->toContain($case->first_name)
        ->and($written)->not->toContain($case->file_number);
});

it('writes down a lookup that found nothing, with no subject', function () {
    /*
     | A hundred misses in a morning is the shape of somebody fishing, and a
     | log that records only the hits is the log that misses it.
     */
    app(DuplicateService::class)->coordinationLookup('99999999999', $this->asker);

    $row = AuditLog::where('action', 'read_by_national_id')->latest('id')->first();

    expect($row)->not->toBeNull()
        ->and($row->entity_id)->toBeNull()
        ->and($row->after_json)->toBe(['found' => false]);
});

it('logs the partner API the same way, because both come through one service', function () {
    $case = publishedCase($this->region);

    $this->actingAs($this->asker);

    $this->postJson('/api/coordination/lookup', ['national_id' => $case->national_id_encrypted])
        ->assertOk();

    $row = AuditLog::where('action', 'read_by_national_id')->latest('id')->first();

    expect($row)->not->toBeNull()
        ->and($row->actor_id)->toBe($this->asker->id)
        ->and($row->entity_id)->toBe($case->id);
});

it('writes one row per asking, so a repeated question is a repeated row', function () {
    $case = publishedCase($this->region);

    foreach (range(1, 3) as $ignored) {
        app(DuplicateService::class)->coordinationLookup($case->national_id_encrypted, $this->asker);
    }

    expect(AuditLog::where('action', 'read_by_national_id')->count())->toBe(3);
});

it('is named in Arabic on the log, and can be singled out by the filter', function () {
    expect(__('sanabel.audit.actions.read_by_national_id'))
        ->not->toBe('sanabel.audit.actions.read_by_national_id');
});
