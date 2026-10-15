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

    /*
     | T-103 — giving without picking a family. The association asked that the
     | offer on the front page land on the act itself rather than on a list to
     | choose from, and the domain has always had a general donation; it had
     | no door. Behind auth like every other way of giving, because a donation
     | is recorded against a donor.
     */
    Route::get('/donate', GeneralDonation::class)->name('donate.general');

    // The household's own page: their file, and the one question only they
    // may answer — did the money arrive.
    Route::get('/portal/family', FamilyPortal::class)->name('family.portal');

    // Delegate field app (T-14). The PWA shell; data syncs through /api/visits/sync.
    Route::get('/field', [FieldController::class, 'index'])->name('field');
});

Route::get('/field/manifest.webmanifest', [FieldController::class, 'manifest'])->name('field.manifest');

// CMS pages last, so a slug never shadows a named route.
Route::get('/{slug}', [PublicController::class, 'page'])->name('page');
