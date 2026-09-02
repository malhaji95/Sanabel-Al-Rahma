<?php

use App\Models\Visit;
use App\Services\VisitSyncService;
use Illuminate\Support\Str;

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
});

/** One queued visit as the device would hold it in IndexedDB. */
function queuedVisit(App\Models\Beneficiary $case, array $overrides = []): array
{
    return array_merge([
        'client_uuid' => (string) Str::uuid(),
        'beneficiary_id' => $case->id,
        'visited_at' => now()->toIso8601String(),
        'note_ar' => 'زيارة ميدانية بدون اتصال',
        'recommendation' => 'approve',
        'is_reassessment' => false,
        'data' => ['members' => 5, 'rooms' => 2],
    ], $overrides);
}

it('completes a visit with no network and syncs it once the device is back online', function () {
    $delegate = userWithRole('delegate', ['region_id' => $this->region->id]);
    $case = familyOf($this->region);

    // The device filled the form offline; the queue is pushed later.
    $queued = queuedVisit($case);

    $result = $this->actingAs($delegate, 'sanctum')
        ->postJson(route('visits.sync'), ['visits' => [$queued]])
        ->assertCreated()
        ->json();

    $visit = Visit::where('client_uuid', $queued['client_uuid'])->first();

    expect($result['synced'])->toBe(1)
        ->and($visit)->not->toBeNull()
        ->and($visit->delegate_id)->toBe($delegate->id)
        ->and($visit->note_ar)->toBe('زيارة ميدانية بدون اتصال')
        ->and($visit->payload_json)->toBe(['members' => 5, 'rooms' => 2])
        ->and($visit->synced_at)->not->toBeNull();
});

it('creates one visit, not two, when the device syncs the same queue twice', function () {
    $delegate = userWithRole('delegate', ['region_id' => $this->region->id]);
    $case = familyOf($this->region);
    $queued = queuedVisit($case);

    $this->actingAs($delegate, 'sanctum')->postJson(route('visits.sync'), ['visits' => [$queued]])->assertCreated();
    $this->actingAs($delegate, 'sanctum')->postJson(route('visits.sync'), ['visits' => [$queued]])->assertCreated();

    expect(Visit::where('client_uuid', $queued['client_uuid'])->count())->toBe(1)
        ->and(Visit::count())->toBe(1);
});

it('is protected by a unique index on client_uuid, not only by application code', function () {
    $case = familyOf($this->region);
    $uuid = (string) Str::uuid();

    Visit::factory()->create(['beneficiary_id' => $case->id, 'client_uuid' => $uuid]);

    expect(fn () => Visit::factory()->create([
        'beneficiary_id' => $case->id,
        'client_uuid' => $uuid,
    ]))->toThrow(Illuminate\Database\QueryException::class);
});

it('stores a new visit and flags a conflict when the server changed since the last sync', function () {
    $delegate = userWithRole('delegate', ['region_id' => $this->region->id]);
    $case = familyOf($this->region);

    // The device last saw the case an hour ago.
    $deviceSawAt = now()->subHour();

    // Meanwhile the office edited the file.
    $case->forceFill(['marital_status' => 'widowed'])->save();

    $queued = queuedVisit($case, [
        'base_version_at' => $deviceSawAt->toIso8601String(),
        'note_ar' => 'ملاحظة المندوب',
    ]);

    $this->actingAs($delegate, 'sanctum')
        ->postJson(route('visits.sync'), ['visits' => [$queued]])
        ->assertCreated()
        ->assertJsonPath('conflicts', 1);

    $visit = Visit::where('client_uuid', $queued['client_uuid'])->first();

    expect($visit->conflict_flag)->toBeTrue()
        ->and($visit->conflict_reason)->toContain('nothing was overwritten')
        // Nothing on the case was overwritten by the sync.
        ->and($case->fresh()->marital_status)->toBe('widowed');
});

it('does not flag a conflict when the case has not moved since the device last saw it', function () {
    $delegate = userWithRole('delegate', ['region_id' => $this->region->id]);
    $case = familyOf($this->region);

    $queued = queuedVisit($case, ['base_version_at' => now()->addMinute()->toIso8601String()]);

    $this->actingAs($delegate, 'sanctum')
        ->postJson(route('visits.sync'), ['visits' => [$queued]])
        ->assertCreated()
        ->assertJsonPath('conflicts', 0);
});

it('syncs a whole queue in one request', function () {
    $delegate = userWithRole('delegate', ['region_id' => $this->region->id]);
    $cases = collect(range(1, 3))->map(fn () => familyOf($this->region));

    $queue = $cases->map(fn ($case) => queuedVisit($case))->all();

    $result = app(VisitSyncService::class)->syncQueue($queue, $delegate);

    expect($result['synced'])->toBe(3)
        ->and(Visit::count())->toBe(3);
});
