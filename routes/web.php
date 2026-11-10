<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\FieldController;
use App\Http\Controllers\GovernanceDocumentController;
use App\Http\Controllers\PublicController;
use App\Http\Middleware\KeepFamiliesOutOfTheDonorPortal;
use App\Livewire\BrowseCases;
use App\Livewire\BrowseSponsorships;
use App\Livewire\ContactDesk;
use App\Livewire\DonorBasket;
use App\Livewire\DonorFavourites;
use App\Livewire\DonorPortal;
use App\Livewire\FamilyPortal;
use App\Livewire\GeneralDonation;
use App\Livewire\GiveAgift;
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

/*
 | T-117 — one address per campaign, so its story can be read and sent on.
 | The list clamps the text to three lines, which is a card and not a story;
 | «عندنا موضوع القصة او المعلومات اكثر, نقدر نضيفها بي الحملات», 22 October.
 */
Route::get('/campaigns/{campaign}', [PublicController::class, 'campaign'])->name('campaign.public');
Route::get('/cases', BrowseCases::class)->name('cases.browse');

/*
 | T-228 — «مابدنا الكفالات, تظهر في الحالات / ولكن, في صفحة الكفالات لازم
 | يظهر كل كفاله, ايش نوعها يتيم ارمله طالب», 1 November. The row of doors
 | T-208 put on the list of cases has a page of its own.
 */
Route::get('/sponsorships', BrowseSponsorships::class)->name('sponsorships.browse');

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

/*
 | T-258 — «تواصل معنا» is a desk, not a page: a ticket, a reference and a
 | way to follow it. Declared before the catch-all slug so it wins over the
 | content page of the same name, whose words it still draws underneath.
 */
Route::get('/contact', ContactDesk::class)->name('contact');

// T-242 — the page a donor fills in to give a gift.
Route::get('/gift', GiveAgift::class)->name('gift.give');

/*
 | T-241 — the gift card, at a private address. No account, because the
 | recipient has none, and a token rather than an id so one card's address
 | cannot be guessed from another's.
 */
Route::get('/gift/card/{token}', [PublicController::class, 'giftCard'])->name('gift.card');

/*
 | T-262 — the receipt the platform hands the donor, at a private address and
 | for the same reasons: a guest donation has no account to read it from, and
 | one receipt's address says nothing about another's.
 */
Route::get('/receipt/{token}', [PublicController::class, 'donationReceipt'])->name('donation.receipt');

Route::get('/login', [LoginController::class, 'show'])->name('login')->middleware('guest');

// T-57 — the public login form had no limit at all, while the panels carried
// Filament's own five a minute. Keyed on the email being tried as well as the
// address, with an hourly cap that catches a slow trickle under the per-minute
// one.
Route::post('/login', [LoginController::class, 'store'])->middleware(['guest', 'throttle:login']);
Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

// Donor portal (T-26).
Route::middleware('auth')->group(function () {
    /*
     | T-220 — «حساب عيلة وبدها تتبرع؟ هاد الخيار مرفوض وغير ممكن»,
     | 1 November. T-194 took the donor portal out of a household's menu and
     | left its address reachable by typing it. These three are the donor's
     | screens, so these three are the ones shut.
     */
    Route::middleware(KeepFamiliesOutOfTheDonorPortal::class)->group(function () {
        Route::get('/portal', DonorPortal::class)->name('donor.portal');
        Route::get('/portal/basket', DonorBasket::class)->name('donor.basket');

        // T-180 — the families this donor kept, to come back to. Paying does
        // not take one off the list; only the donor does.
        Route::get('/portal/favourites', DonorFavourites::class)->name('donor.favourites');
    });

    // The household's own page: their file, and the one question only they
    // may answer — did the money arrive.
    Route::get('/portal/family', FamilyPortal::class)->name('family.portal');

    // Delegate field app (T-14). The PWA shell; data syncs through /api/visits/sync.
    Route::get('/field', [FieldController::class, 'index'])->name('field');
});

Route::get('/field/manifest.webmanifest', [FieldController::class, 'manifest'])->name('field.manifest');

// CMS pages last, so a slug never shadows a named route.
/*
 | T-291 — a guarded governance document is read through the application so
 | the permission is checked at the moment of opening and the opening is
 | written down. Above the catch-all page route, which would swallow it.
 */
Route::middleware('auth')
    ->get('/governance/documents/{version}', [GovernanceDocumentController::class, 'show'])
    ->name('governance.document');

Route::get('/{slug}', [PublicController::class, 'page'])->name('page');
