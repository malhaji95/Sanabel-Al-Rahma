<?php

namespace App\Console\Commands;

use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * T-97 — rebuilds the demonstration database from nothing.
 *
 * The deployment seeds synthetic families once and never again: it checks
 * whether any family exists and skips if one does. That is right for a demo
 * that is being shown — nobody wants it emptied by a deploy — and it is why
 * the demo drifted. Everything added to the seeder after the first boot never
 * ran: the association's own wallet, the campaigns, the verified transfers,
 * the sponsorship, the distribution. The screens were there and had nothing
 * on them.
 *
 * So the rebuild is deliberate, loud and refuses to be an accident:
 *
 *   · it runs only where DEMO_SEED is on — the same flag that decides whether
 *     synthetic families may exist at all, and which a real deployment must
 *     never set;
 *   · it needs a token, and remembers the one it used. Leaving DEMO_RESET set
 *     in the environment does not wipe the database on every deploy: the
 *     token has to change for it to run again;
 *   · it refuses without --force, so a hand at a terminal is asked once.
 *
 * It drops every table. There is no undo, which is the point of the three
 * conditions above.
 */
class ResetDemo extends Command
{
    protected $signature = 'demo:reset
        {--force : Required. There is no undo}
        {--token= : Records this token, so the same one does not run twice}';

    protected $description = 'Empty the demonstration database and seed it again from scratch';

    /** Where the last token is remembered, so one token means one rebuild. */
    public const TOKEN_SETTING = 'demo_reset_token';

    public function handle(): int
    {
        if (! config('sanabel.demo_seed')) {
            $this->error(__('sanabel.demo.reset_refused'));

            return self::FAILURE;
        }

        if (! $this->option('force')) {
            $this->error(__('sanabel.demo.reset_needs_force'));

            return self::FAILURE;
        }

        $token = (string) $this->option('token');

        if ($token !== '' && Setting::value(self::TOKEN_SETTING) === $token) {
            $this->info(__('sanabel.demo.reset_already', ['token' => $token]));

            return self::SUCCESS;
        }

        $this->warn(__('sanabel.demo.reset_starting'));

        // migrate:fresh drops every table and runs the migrations again, so
        // the database that comes back is the schema this release expects —
        // not the one the first boot happened to leave behind.
        Artisan::call('migrate:fresh', ['--force' => true], $this->output);
        Artisan::call('db:seed', ['--force' => true], $this->output);
        Artisan::call('db:seed', ['--class' => 'SyntheticDataSeeder', '--force' => true], $this->output);
        Artisan::call('db:seed', ['--class' => 'TrainingStageSeeder', '--force' => true], $this->output);

        if ($token !== '') {
            Setting::put(self::TOKEN_SETTING, $token);
        }

        $this->info(__('sanabel.demo.reset_done'));

        return self::SUCCESS;
    }
}
