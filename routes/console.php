<?php

use Illuminate\Support\Facades\Schedule;

/*
 | The scheduled work docs/03-rules.md calls for. Everything else is triggered
 | by a person acting in a panel.
 */

// Rule 6 — expired reservations are released every five minutes.
Schedule::command('sanabel:release-expired-baskets')->everyFiveMinutes()->withoutOverlapping();

Schedule::command('sanabel:send-notifications')->everyMinute()->withoutOverlapping();

Schedule::command('sanabel:sponsorship-cycle')->dailyAt('06:00');

Schedule::command('sanabel:flag-reassessments')->dailyAt('06:15');

Schedule::command('sanabel:expire-referrals')->dailyAt('06:30');
