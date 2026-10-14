<?php

namespace Tests;

use App\Http\Middleware\RequireTwoFactor;
use App\Models\Setting;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Settings are held for the life of a request. Tests share one process, and
     * a rolled-back transaction fires no model events, so what one test wrote
     * would otherwise still be in memory for the next one.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Setting::forgetLoaded();
    }

    /**
     * A second person, at a second browser.
     *
     * T-93 — a session is now tied to the password it was opened with, so
     * handing one session to another account is exactly what the application
     * refuses: in a browser that is a stolen cookie, and the user is signed
     * out. A test that compares what two people see therefore starts the
     * second one on a clean session, which is what two people have.
     *
     * The second-factor gate is carried over rather than flushed with the
     * rest: SecurityTest owns that gate, and every other panel test declares
     * it a precondition with passTwoFactor().
     */
    protected function actingFreshly(Authenticatable $user): static
    {
        $twoFactor = session(RequireTwoFactor::SESSION_KEY);

        $this->flushSession();
        $this->app['auth']->forgetGuards();

        if ($twoFactor) {
            session([RequireTwoFactor::SESSION_KEY => $twoFactor]);
        }

        return $this->actingAs($user);
    }
}
