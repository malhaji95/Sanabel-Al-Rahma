<?php

namespace Tests;

use App\Models\Setting;
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
}
