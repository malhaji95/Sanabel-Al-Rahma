<?php

use App\Models\Distribution;
use App\Models\DistributionItem;
use App\Services\CaseService;
use App\Services\DistributionService;

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->admin = userWithRole('admin');
});

it('cannot close a case without a delivery proof', function () {
    $case = publishedCase($this->region);

    expect(fn () => app(CaseService::class)->close($case))
        ->toThrow(RuntimeException::class, __('sanabel.cases.close_requires_proof'));

    // A delivery row with no proof is still not enough.
    App\Models\Delivery::create([
        'beneficiary_id' => $case->id,
        'type' => 'cash',
        'confirmed_by' => $this->admin->id,
        'confirmed_at' => now(),
    ]);

    expect(fn () => app(CaseService::class)->close($case->refresh()))
        ->toThrow(RuntimeException::class);

    app(CaseService::class)->confirmDelivery($case, $this->admin, 'cash', proofMediaId: 42);

    expect(app(CaseService::class)->close($case->refresh())->status)->toBe('graduated');
});

it('freezes an approved distribution list and never regenerates it', function () {
    $a = publishedCase($this->region);
    $b = publishedCase($this->region);

    $distribution = Distribution::factory()->create([
        'region_id' => $this->region->id,
        'per_family_amount' => 50_000,
        'criteria_json' => ['support_type' => 'monthly', 'limit' => 10],
    ]);

    app(DistributionService::class)->generateList($distribution);
    expect($distribution->fresh()->list_json)->toHaveCount(2);

    app(DistributionService::class)->approve($distribution->fresh(), $this->admin);
    $frozen = $distribution->fresh()->list_json;

    // A third family published after approval must not appear in the frozen list.
    publishedCase($this->region);

    expect(fn () => app(DistributionService::class)->generateList($distribution->fresh()))
        ->toThrow(RuntimeException::class, __('sanabel.distributions.list_frozen'));

    expect(fn () => $distribution->fresh()->update(['list_json' => []]))
        ->toThrow(RuntimeException::class, __('sanabel.distributions.list_frozen'));

    expect($distribution->fresh()->list_json)->toBe($frozen)
        ->and($distribution->fresh()->items)->toHaveCount(2);
});

it('records which items failed on a partial execution', function () {
    $a = publishedCase($this->region);
    $b = publishedCase($this->region);

    $distribution = Distribution::factory()->create([
        'region_id' => $this->region->id,
        'per_family_amount' => 50_000,
        'criteria_json' => ['support_type' => 'monthly', 'limit' => 10],
    ]);

    app(DistributionService::class)->generateList($distribution);
    app(DistributionService::class)->approve($distribution->fresh(), $this->admin);

    $items = $distribution->fresh()->items()->orderBy('id')->get();

    app(DistributionService::class)->execute($items[0], proofMediaId: 7);
    expect($distribution->fresh()->status)->toBe('executing');

    app(DistributionService::class)->fail($items[1], 'المحفظة غير فعالة');

    expect($distribution->fresh()->status)->toBe('partial')
        ->and(DistributionItem::where('status', 'executed')->count())->toBe(1)
        ->and(DistributionItem::where('status', 'failed')->first()->failure_reason_ar)
        ->toBe('المحفظة غير فعالة');
});

it('marks a distribution completed only when every item was executed', function () {
    $delegate = userWithRole('delegate', ['region_id' => $this->region->id]);

    foreach (range(1, 2) as $i) {
        publishedCase($this->region)->forceFill(['created_by' => $delegate->id])->save();
    }

    $distribution = Distribution::factory()->create([
        'region_id' => $this->region->id,
        'per_family_amount' => 50_000,
        'criteria_json' => ['support_type' => 'monthly', 'limit' => 10],
    ]);

    app(DistributionService::class)->generateList($distribution);
    app(DistributionService::class)->approve($distribution->fresh(), $this->admin);

    foreach ($distribution->fresh()->items as $item) {
        app(DistributionService::class)->execute($item, proofMediaId: 9);
    }

    expect($distribution->fresh()->status)->toBe('completed')
        ->and(App\Models\AppNotification::where('template_key', 'distribution_executed')->count())
        ->toBeGreaterThanOrEqual(2);
});

it('refuses to approve a distribution before the list is generated', function () {
    $distribution = Distribution::factory()->create(['region_id' => $this->region->id]);

    expect(fn () => app(DistributionService::class)->approve($distribution, $this->admin))
        ->toThrow(RuntimeException::class);
});
