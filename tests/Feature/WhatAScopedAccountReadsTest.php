<?php

use App\Filament\Pages\OperationalReports;
use App\Filament\Resources\ComplaintResource;
use App\Filament\Widgets\OverviewStats;
use App\Models\Complaint;
use Filament\Facades\Filament;

/*
 | T-134 — the association's notes 1 and 3 of 28 October: «بالنسبة لمشرف
 | المنطقة عند KPI Cards ضمن لوحة التحكم يجب ان تظهر له فقط البيانات التي
 | تخصه نفس حالة المندوب» and «مشرف المنطقة ما الشكاوي التي تظهر عنده
 | والتقارير التشغيلية حتى المندوب كذلك؟».
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    passTwoFactor();
});

it('keeps the association money out of an area supervisor report', function () {
    $this->actingAs(userWithRole('area_supervisor', ['region_id' => $this->region->id]));

    $page = new OperationalReports;

    expect($page->seesMoney())->toBeFalse()
        ->and($page->sections())->toBe(['families'])
        // And the figures are not computed either, let alone drawn.
        ->and(array_keys($page->getReport()))->toBe(['families']);
})->group('AUTH-02');

it('gives the association accounts every block of it', function () {
    $this->actingAs(userWithRole('executive_director'));

    $page = new OperationalReports;

    expect($page->seesMoney())->toBeTrue()
        ->and($page->sections())->toContain('money')
        ->and($page->getReport())->toHaveKeys(['money', 'families', 'sponsorships', 'failures', 'campaigns']);
})->group('AUTH-01');

it('closes the report to a delegate, whose reports are their own work', function () {
    $this->actingAs(userWithRole('delegate', ['region_id' => placeIn($this->region, 'village')->id]));

    expect(OperationalReports::canAccess())->toBeFalse();
})->group('AUTH-02');

it('says on the supervisor dashboard that the figures are their own area', function () {
    $this->actingAs(userWithRole('area_supervisor', ['region_id' => $this->region->id]));

    $method = new ReflectionMethod(OverviewStats::class, 'getStats');
    $method->setAccessible(true);

    $descriptions = collect($method->invoke(new OverviewStats))
        ->map(fn ($stat) => (string) $stat->getDescription())
        ->filter()
        ->values();

    expect($descriptions)->not->toBeEmpty()
        ->and($descriptions->every(fn (string $text) => str_contains($text, __('sanabel.dashboard.in_your_area'))))
        ->toBeTrue();
});

it('leaves the association-wide dashboard unlabelled, because it is not one area', function () {
    $this->actingAs(userWithRole('executive_director'));

    $method = new ReflectionMethod(OverviewStats::class, 'getStats');
    $method->setAccessible(true);

    $descriptions = collect($method->invoke(new OverviewStats))
        ->map(fn ($stat) => (string) $stat->getDescription());

    expect($descriptions->filter(fn (string $t) => str_contains($t, __('sanabel.dashboard.in_your_area'))))
        ->toBeEmpty();
});

it('shows a delegate the complaints they filed and nobody else', function () {
    $delegate = userWithRole('delegate', ['region_id' => placeIn($this->region, 'village')->id]);
    $other = userWithRole('area_supervisor', ['region_id' => $this->region->id]);

    $this->actingAs($delegate);

    $mine = Complaint::create([
        'reference_no' => 'C-MINE', 'subject_ar' => 'شكوى المندوب',
        'body_ar' => 'نصّ', 'category' => 'service', 'status' => 'open',
    ]);

    $this->actingAs($other);

    $theirs = Complaint::create([
        'reference_no' => 'C-THEIRS', 'subject_ar' => 'شكوى المشرف',
        'body_ar' => 'نصّ', 'category' => 'service', 'status' => 'open',
    ]);

    $this->actingAs($delegate);

    $visible = ComplaintResource::getEloquentQuery()->pluck('reference_no');

    expect($visible)->toContain('C-MINE')
        ->and($visible)->not->toContain('C-THEIRS');
})->group('AUTH-02');

it('shows a family their own complaint from their portal account, and the handler all of them', function () {
    $family = userWithRole('beneficiary');

    $this->actingAs(userWithRole('executive_director'));

    $filed = Complaint::create([
        'reference_no' => 'C-FAMILY', 'subject_ar' => 'شكوى أسرة', 'body_ar' => 'نصّ',
        'category' => 'payment', 'status' => 'open', 'submitted_by' => $family->id,
    ]);

    // The account that may handle one reads every complaint, as before.
    expect(ComplaintResource::getEloquentQuery()->pluck('reference_no'))->toContain('C-FAMILY');

    // And the family reads the one filed from their own account.
    $this->actingAs($family);

    expect(ComplaintResource::getEloquentQuery()->pluck('reference_no'))->toContain('C-FAMILY');
})->group('AUTH-01');

it('still reads them all to the board of trustees, which oversees', function () {
    $this->actingAs(userWithRole('executive_director'));

    Complaint::create([
        'reference_no' => 'C-OVERSIGHT', 'subject_ar' => 'شكوى', 'body_ar' => 'نصّ',
        'category' => 'service', 'status' => 'open',
    ]);

    $this->actingAs(userWithRole('council'));

    expect(ComplaintResource::getEloquentQuery()->pluck('reference_no'))->toContain('C-OVERSIGHT');
})->group('AUTH-01');
