<?php

namespace App\Providers;

use App\Payments\ManualDriver;
use App\Payments\PaymentGateway;
use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
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

        // T-38 — the national-ID search is the one route worth rate limiting.
        RateLimiter::for('national-id', fn (Request $request) => Limit::perMinute(10)->by($request->user()?->id ?: $request->ip()));

        // Rule 3 — nothing is hard-deleted. Guarded here as well as in the policies.
        Model::preventLazyLoading(false);
    }
}
