<?php

use App\Models\Beneficiary;
use App\Models\Visit;
use App\Services\VisitSyncService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
});

/** One queued visit as the device would hold it in IndexedDB. */
function queuedVisit(Beneficiary $case, array $overrides = []): array
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
    ]))->toThrow(QueryException::class);
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

/*
 | Two bugs shipped past this suite because it exercises the sync endpoint
 | directly, never the browser. In a real browser the field app was dead:
 |
 |  1. Blade drops the newline after an @js() directive, so the next statement
 |     was glued onto the assignment and the whole inline script failed to
 |     parse. fieldApp() never existed, nothing on the page worked.
 |  2. /api/visits/sync sits behind auth:sanctum, but the delegate authenticates
 |     with a session cookie. Without statefulApi() Sanctum only looks for a
 |     bearer token, so every sync answered 401 and the queue never drained.
 */
it('emits an inline script whose statements are terminated', function () {
    $delegate = userWithRole('delegate', ['region_id' => regionWithRates()->id]);

    $html = $this->actingAs($delegate)->get(route('field'))->assertSuccessful()->getContent();

    preg_match('/<script>(.*?)<\/script>/s', $html, $m);
    expect($m[1] ?? '')->not->toBeEmpty();

    // Same line only: a newline between two statements is perfectly normal.
    expect($m[1])->not->toMatch("/'[ \t]+(await|this|if|return)\b/");
});

it('lets a session-authenticated delegate reach the sync endpoint', function () {
    // statefulApi() is what makes the session count; without it this is a 401.
    $middleware = file_get_contents(base_path('bootstrap/app.php'));

    expect($middleware)->toContain('statefulApi()');

    $delegate = userWithRole('delegate', ['region_id' => regionWithRates()->id]);

    // The payload is validated separately; what matters here is that the
    // session authenticates at all, so anything but 401 proves the point.
    $response = $this->actingAs($delegate)->postJson(route('visits.sync'), ['visits' => []]);

    expect($response->status())->not->toBe(401);
});

it('never writes a visit to the device in the clear', function () {
    // The encryption itself is browser-side and was proved in a real browser:
    // the stored row holds only client_uuid, synced, queued_at, iv and sealed,
    // and the note inside it does not appear in the raw record. This guards the
    // source against someone putting the plain visit back.
    $source = file_get_contents(resource_path('js/field.js'));

    expect($source)->toContain('AES-GCM')
        // Non-extractable: no script on the origin can read the key's bytes.
        // Non-extractable: no script on the origin can read the key's bytes.
        ->and($source)->toContain("{ name: 'AES-GCM', length: 256 }, false")
        ->and($source)->toContain('crypto.subtle.encrypt')
        ->and($source)->toContain('crypto.subtle.decrypt');

    // What the queue writer actually stores. markSynced re-puts the same
    // sealed row with its flag flipped, which is why this looks at queueVisit
    // rather than at every put() in the file.
    preg_match('/export async function queueVisit\(.*?\n\}/s', $source, $writer);
    preg_match("/tx\(db, 'readwrite'\)\.put\(\{(.*?)\}\)/s", $writer[0], $written);

    $fields = collect(explode(',', $written[1]))
        ->map(fn ($line) => trim(explode(':', $line)[0]))
        ->filter()
        ->values()
        ->all();

    // Exactly the row proved in the browser: two index fields, a timestamp,
    // and the sealed blob. Nothing from the visit itself.
    expect($writer[0])->toContain('seal(db, body)')
        ->and($fields)->toBe(['client_uuid', 'synced', 'queued_at', 'iv', 'sealed']);
});
