<?php

use App\Http\Controllers\Api\CaseController;
use App\Http\Controllers\Api\CoordinationController;
use App\Http\Controllers\Api\DonationController;
use App\Http\Controllers\Api\ProviderReferralController;
use App\Http\Controllers\Api\VisitSyncController;
use App\Http\Controllers\Donor\BasketController;
use App\Http\Controllers\Donor\CampaignController;
use App\Http\Controllers\Donor\CaseBrowseController;
use App\Http\Controllers\Donor\JobMarketController;
use App\Http\Controllers\Donor\MyDonationsController;
use Illuminate\Support\Facades\Route;

/*
 | Donor routes. Every one of them answers with MaskedCaseResource (rule 2);
 | docs/06-tests.md has one leak test per route below.
 */
Route::middleware(['auth:sanctum'])->prefix('donor')->name('donor.')->group(function () {
    Route::get('cases/{supportType}', [CaseBrowseController::class, 'index'])->name('cases.index');
    Route::get('case/{case}', [CaseBrowseController::class, 'show'])->name('cases.show');

    Route::get('basket', [BasketController::class, 'show'])->name('basket.show');
    Route::post('basket/items', [BasketController::class, 'addItem'])->name('basket.add');
    Route::post('basket/reserve', [BasketController::class, 'reserve'])->name('basket.reserve');

    Route::get('donations', [MyDonationsController::class, 'index'])->name('donations.index');
    Route::post('donations', [DonationController::class, 'store'])->name('donations.store');

    Route::get('campaigns', [CampaignController::class, 'index'])->name('campaigns.index');
    Route::get('campaigns/{campaign}', [CampaignController::class, 'show'])->name('campaigns.show');

    Route::get('jobs', [JobMarketController::class, 'index'])->name('jobs.index');
    Route::post('jobs/{profile}/contact', [JobMarketController::class, 'requestContact'])->name('jobs.contact');
});

/*
 | Internal routes. `council` is denied every one of the write routes below —
 | docs/06-tests.md has one test per route.
 */
Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('cases', [CaseController::class, 'index'])->name('cases.index');
    Route::get('cases/{case}', [CaseController::class, 'show'])->name('cases.show');
    Route::post('cases', [CaseController::class, 'store'])->name('cases.store');
    Route::post('cases/{case}/approve', [CaseController::class, 'approve'])->name('cases.approve');
    Route::post('cases/{case}/reject', [CaseController::class, 'reject'])->name('cases.reject');
    Route::post('cases/{case}/publish', [CaseController::class, 'publish'])->name('cases.publish');
    Route::post('cases/{case}/change-requests', [CaseController::class, 'requestChange'])->name('cases.change');
    Route::post('cases/{case}/deliveries', [CaseController::class, 'confirmDelivery'])->name('cases.delivery');

    Route::post('donations/{donation}/verify', [DonationController::class, 'verify'])->name('donations.verify');
    Route::post('donations/{donation}/reject', [DonationController::class, 'reject'])->name('donations.reject');

    // The only route that takes a national ID — rate limited (T-38).
    Route::post('coordination/lookup', [CoordinationController::class, 'lookup'])
        ->middleware('throttle:national-id')
        ->name('coordination.lookup');

    Route::post('visits/sync', [VisitSyncController::class, 'store'])->name('visits.sync');

    Route::get('referrals/{code}', [ProviderReferralController::class, 'verify'])->name('referrals.verify');
    Route::post('referrals/{code}/redeem', [ProviderReferralController::class, 'redeem'])->name('referrals.redeem');
});
