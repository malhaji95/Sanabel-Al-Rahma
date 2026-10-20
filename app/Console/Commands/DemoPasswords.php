<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * T-114 — why the demonstration accounts stopped matching their stated
 * passwords, and the lever that fixes it without emptying the database.
 *
 * Every seeder creates its accounts with firstOrCreate, which is right: a
 * deploy must not overwrite what is already there. The consequence nobody
 * had followed through is that a password is hashed exactly once, the moment
 * the row is first written, and no later deploy touches it again. So
 * SEED_ADMIN_PASSWORD and SEED_DEMO_PASSWORD only ever describe the accounts
 * created on the deploy that first set them. Change the variable afterwards
 * and the environment says one thing while the database says another — which
 * is exactly what the association met on 20 October, holding two passwords
 * that had never been hashed into anything.
 *
 * The blunt fix was to change DEMO_RESET and rebuild from nothing, which also
 * throws away every row the association has typed into the demonstration
 * since. This is the narrow one: it sets the passwords and touches nothing
 * else.
 *
 * It refuses outside a demonstration, and it will not touch an account that
 * is not one of the seeded ones — a demonstration deployment that later
 * carries a real person's account must not have that person's password
 * rewritten by a deploy.
 */
class DemoPasswords extends Command
{
    protected $signature = 'demo:passwords {--dry-run : Say what would change and change nothing}';

    protected $description = 'Set the demonstration accounts to the passwords in the environment';

    /** The seeded accounts all live on this domain, and nothing else does. */
    public const DEMO_DOMAIN = '@sanabel.local';

    /** The one account that takes SEED_ADMIN_PASSWORD rather than the demo one. */
    public const ADMIN_EMAIL = 'admin@sanabel.local';

    public function handle(): int
    {
        if (! config('sanabel.demo_seed')) {
            $this->error(__('sanabel.demo.passwords_refused'));

            return self::FAILURE;
        }

        $admin = (string) env('SEED_ADMIN_PASSWORD', '');
        $demo = (string) env('SEED_DEMO_PASSWORD', '');

        if ($admin === '' && $demo === '') {
            $this->warn(__('sanabel.demo.passwords_none_set'));

            return self::SUCCESS;
        }

        $changed = 0;

        foreach (User::where('email', 'like', '%'.self::DEMO_DOMAIN)->cursor() as $user) {
            $password = $user->email === self::ADMIN_EMAIL ? ($admin ?: $demo) : ($demo ?: $admin);

            if ($password === '') {
                continue;
            }

            // Already right: say nothing and write nothing, so a deploy that
            // changes no password leaves no audit entries behind it.
            if (Hash::check($password, $user->password)) {
                continue;
            }

            if (! $this->option('dry-run')) {
                $user->forceFill(['password' => Hash::make($password)])->save();
            }

            $this->line('  '.$user->email);
            $changed++;
        }

        $this->info(__('sanabel.demo.passwords_done', ['count' => $changed]));

        return self::SUCCESS;
    }
}
