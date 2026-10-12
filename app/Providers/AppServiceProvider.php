<?php

namespace App\Providers;

use App\Payments\ManualDriver;
use App\Payments\PaymentGateway;
use App\Services\TransferRouting;
use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Rule 7 — one PaymentGateway interface, one ManualDriver.
        $this->app->bind(PaymentGateway::class, ManualDriver::class);

        // Shared so the two routing settings are read once for a whole donor
        // list rather than once per card.
        $this->app->singleton(TransferRouting::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Every page is right-to-left Arabic.
        Carbon::setLocale(config('app.locale'));

        // A native date input is rendered by the browser, in the browser's own
        // locale and direction: an Arabic page showed 'mm/dd/yyyy'. Filament's
        // own picker follows the page instead. DatePicker extends
        // DateTimePicker, so one registration covers both; the format is chosen
        // per instance rather than in a second callback, whose ordering against
        // this one would decide whether a date-only field grew a '00:00'.
        DateTimePicker::configureUsing(fn (DateTimePicker $picker) => $picker
            ->native(false)
            ->displayFormat($picker instanceof DatePicker ? 'd/m/Y' : 'd/m/Y H:i'));

        /*
         | T-60 — AUTH-04. Filament's own default page-size list offers "all",
         | which on the families or the donations table means one click pulls
         | every row the account can reach into a single response. Every list
         | is paginated (CLAUDE.md §5), so the cap belongs here rather than in
         | twenty table definitions, and a table that wants its own sizes still
         | sets them.
         */
        Table::configureUsing(fn (Table $table) => $table
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25));

        $this->registerRateLimits();

        /*
         | T-66 — Livewire registers its own update route, so the limit is put
         | on it here rather than in routes/web.php. 'web' is kept, because
         | dropping it would take the session and the CSRF check with it.
         */
        Livewire::setUpdateRoute(fn ($handle) => Route::post('/livewire/update', $handle)
            ->middleware(['web', 'throttle:livewire'])
            ->name('livewire.update'));

        // Rule 3 — nothing is hard-deleted. Guarded here as well as in the policies.
        Model::preventLazyLoading(false);
    }

    /**
     * T-57 — APP-04 and IAM-03 of the security roadmap.
     *
     * Before this, one route in the whole application was rate limited. Every
     * limit is keyed to the signed-in account where there is one and to the
     * address otherwise, so one noisy delegate cannot spend the association's
     * whole allowance, and an unauthenticated caller is counted on its own.
     *
     * The numbers are config, not code, so a limit is raised without a deploy.
     */
    private function registerRateLimits(): void
    {
        $limits = config('sanabel.security.rate_limits');

        $byActor = fn (Request $request) => $request->user()?->id ?: $request->ip();

        // Reads.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute($limits['api'])->by($byActor($request)));

        // Writes: a basket hold, a declared transfer, an approval.
        RateLimiter::for('api-write', fn (Request $request) => Limit::perMinute($limits['api_write'])->by($byActor($request)));

        // A field app coming back online pushes its queued visits in a burst.
        RateLimiter::for('sync', fn (Request $request) => Limit::perMinute($limits['sync'])->by($byActor($request)));

        // The one route that takes a national ID (T-38).
        RateLimiter::for('national-id', fn (Request $request) => Limit::perMinute($limits['national_id'])->by($byActor($request)));

        /*
         | T-66 — the Livewire endpoint. T-57 put a limit on every api/ route
         | and a test held it there, but the browser does not write through
         | api/: the donor portal, the family portal and every panel screen
         | write through /livewire/update, and the public case list reaches it
         | without signing in. It was the one unthrottled write path left.
         */
        RateLimiter::for('livewire', fn (Request $request) => Limit::perMinute($limits['livewire'])->by($byActor($request)));

        /*
         | The public login form, which had no limit at all. Keyed on the email
         | being tried as well as the address, so guessing at one account is
         | counted separately from an address trying many — and the hourly cap
         | is what stops a slow trickle that stays under the per-minute one.
         */
        RateLimiter::for('login', function (Request $request) use ($limits) {
            $key = mb_strtolower((string) $request->input('email')).'|'.$request->ip();

            return [
                Limit::perMinute($limits['login'])->by($key),
                Limit::perHour($limits['login_per_hour'])->by($key),
            ];
        });
    }
}
