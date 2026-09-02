<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\FieldController;
use App\Http\Controllers\PublicController;
use App\Livewire\BrowseCases;
use App\Livewire\DonorBasket;
use App\Livewire\DonorPortal;
use Illuminate\Support\Facades\Route;

// Public site — every page is CMS-driven (T-36).
Route::get('/', [PublicController::class, 'home'])->name('home');
Route::get('/news', [PublicController::class, 'news'])->name('news');
Route::get('/news/{slug}', [PublicController::class, 'post'])->name('post');
Route::get('/campaigns', [PublicController::class, 'campaigns'])->name('campaigns.public');
Route::get('/cases', BrowseCases::class)->name('cases.browse');

Route::get('/login', [LoginController::class, 'show'])->name('login')->middleware('guest');
Route::post('/login', [LoginController::class, 'store'])->middleware('guest');
Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

// Donor portal (T-26).
Route::middleware('auth')->group(function () {
    Route::get('/portal', DonorPortal::class)->name('donor.portal');
    Route::get('/portal/basket', DonorBasket::class)->name('donor.basket');

    // Delegate field app (T-14). The PWA shell; data syncs through /api/visits/sync.
    Route::get('/field', [FieldController::class, 'index'])->name('field');
});

Route::get('/field/manifest.webmanifest', [FieldController::class, 'manifest'])->name('field.manifest');

// CMS pages last, so a slug never shadows a named route.
Route::get('/{slug}', [PublicController::class, 'page'])->name('page');
