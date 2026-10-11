<?php

use App\Http\Resources\MaskedCaseResource;
use App\Models\Beneficiary;
use Illuminate\Database\QueryException;

/*
 | T-50 — the old system's file number travels with the family.
 |
 | The association decided on 11 October that Sanabel is the single source of
 | truth once the import is done, that the old system is kept as an archive,
 | and that each family keeps its old number for tracking and against being
 | entered twice.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
});

it('keeps the old number on the file', function () {
    $family = familyOf($this->region, adults: 1, children: 0);
    $family->update(['legacy_file_no' => 'OLD-1042']);

    expect($family->fresh()->legacy_file_no)->toBe('OLD-1042');
});

it('refuses the same old number on a second file', function () {
    familyOf($this->region, adults: 1, children: 0)->update(['legacy_file_no' => 'OLD-1042']);
    $second = familyOf($this->region, adults: 1, children: 0);

    expect(fn () => $second->update(['legacy_file_no' => 'OLD-1042']))
        ->toThrow(QueryException::class);
});

it('lets any number of families carry no old number at all', function () {
    familyOf($this->region, adults: 1, children: 0)->update(['legacy_file_no' => null]);
    familyOf($this->region, adults: 1, children: 0)->update(['legacy_file_no' => null]);

    expect(Beneficiary::whereNull('legacy_file_no')->count())->toBeGreaterThanOrEqual(2);
});

it('never shows the old number to a donor', function () {
    $family = familyOf($this->region, adults: 1, children: 0);
    $family->update(['legacy_file_no' => 'OLD-1042']);

    $payload = (new MaskedCaseResource($family->fresh()))->resolve();

    expect($payload)->not->toHaveKey('legacy_file_no')
        ->and(json_encode($payload, JSON_UNESCAPED_UNICODE))->not->toContain('OLD-1042');
});
