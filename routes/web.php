<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\FieldController;
use App\Http\Controllers\PublicController;
use App\Livewire\BrowseCases;
use App\Livewire\DonorBasket;
use App\Livewire\DonorPortal;
use App\Livewire\FamilyPortal;
use App\Livewire\GeneralDonation;
use Illuminate\Support\Facades\Route;

// Public site — every page is CMS-driven (T-36).
Route::get('/', [PublicController::class, 'home'])->name('home');
Route::get('/news', [PublicController::class, 'news'])->name('news');
Route::get('/news/{slug}', [PublicController::class, 'post'])->name('post');

/*
 | Seeing a piece as a reader will, before anyone else can. It renders the same
 | article page whatever the post's status, so what the editor approves is what
 | goes out — and it is behind auth, so an unpublished draft is not a public URL
 | anyone can guess.
 */
Route::middleware('auth')
    ->get('/news/{slug}/preview', [PublicController::class, 'previewPost'])
    ->name('post.preview');
Route::get('/campaigns', [PublicController::class, 'campaigns'])->name('campaigns.public');
Route::get('/cases', BrowseCases::class)->name('cases.browse');

// One address per family file, so an opportunity can be sent on. It serves the
// same masked card the list serves — nothing here that the list does not show.
Route::get('/cases/{fileNumber}', [PublicController::class, 'case'])->name('opportunity');

/*
 | T-103 — giving without picking a family, and T-108 — without signing in
 | either. «تبرع سريع … يدخل ما يحتاج يدخل ايميل وكلمة مرور، مباشرة ندخل على
 | صفحة التبرع», 17 October.
 |
 | It is not behind auth because the thing it records is a transfer somebody
 | has already made with their own bank, and the reference of that transfer
 | is what identifies it. An account is what lets a donor come back and read
 | their own history; it was never what made the money real. Nothing counts
 | until the finance desk matches the reference against the statement, so an
 | invented record is noise in a queue rather than money in a figure.
 */
Route::get('/donate', GeneralDonation::class)->name('donate.general');

Route::get('/login', [LoginController::class, 'show'])->name('login')->middleware('guest');

// T-57 — the public login form had no limit at all, while the panels carried
// Filament's own five a minute. Keyed on the email being tried as well as the
// address, with an hourly cap that catches a slow trickle under the per-minute
// one.
Route::post('/login', [LoginController::class, 'store'])->middleware(['guest', 'throttle:login']);
Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

// Donor portal (T-26).
Route::middleware('auth')->group(function () {
    Route::get('/portal', DonorPortal::class)->name('donor.portal');
    Route::get('/portal/basket', DonorBasket::class)->name('donor.basket');

    // The household's own page: their file, and the one question only they
    // may answer — did the money arrive.
    Route::get('/portal/family', FamilyPortal::class)->name('family.portal');

    // Delegate field app (T-14). The PWA shell; data syncs through /api/visits/sync.
    Route::get('/field', [FieldController::class, 'index'])->name('field');
});

Route::get('/field/manifest.webmanifest', [FieldController::class, 'manifest'])->name('field.manifest');

// CMS pages last, so a slug never shadows a named route.
Route::get('/{slug}', [PublicController::class, 'page'])->name('page');
