<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

/*
 | T-68 — Redis, a keyspace each.
 |
 | CLAUDE.md §1 names Redis queues as the stack; the deployment fell back to
 | the database for cache, queue and session because one free container has no
 | Redis. This is the configuration that is waiting for the day it does, and
 | APP-07 of the security roadmap asks for exactly the separation below.
 |
 | Laravel's own defaults put the session, the queue and the cache locks on one
 | keyspace. There, `php artisan cache:clear` and a queue flush each reach
 | further than their name: either one signs every user out.
 */

function redisReachable(): bool
{
    try {
        Redis::connection('default')->ping();

        return true;
    } catch (\Throwable) {
        return false;
    }
}

it('gives the cache, the session and the queue a database each', function () {
    $dbs = collect(['default', 'cache', 'session', 'queue'])
        ->mapWithKeys(fn (string $c) => [$c => (int) config("database.redis.{$c}.database")]);

    expect($dbs->unique())->toHaveCount(4)
        ->and($dbs['cache'])->not->toBe($dbs['session'])
        ->and($dbs['queue'])->not->toBe($dbs['session']);
});

it('points the session at its own keyspace the moment redis is chosen, with nothing else to set', function () {
    /*
     | This key means a database connection under the database driver and a
     | Redis connection under the redis one, so it cannot carry one fixed
     | default. The pairing therefore lives in the config rather than in
     | somebody's memory, and it is checked in a fresh process because the
     | environment is read once per boot.
     */
    $run = function (array $env) {
        $process = new \Symfony\Component\Process\Process(
            [PHP_BINARY, 'artisan', 'tinker', '--execute=echo var_export(config("session.connection"), true);'],
            base_path(),
            $env + ['PATH' => getenv('PATH')],
        );
        $process->run();

        return trim($process->getOutput());
    };

    expect($run(['SESSION_DRIVER' => 'redis']))->toContain("'session'")
        ->and($run(['SESSION_DRIVER' => 'database']))->toContain('NULL');
});

it('clears the cache without signing anybody out', function () {
    // The accident this prevents. On one keyspace, clearing the cache takes
    // the sessions with it.
    config([
        'cache.default' => 'redis',
        'session.driver' => 'redis',
        'session.connection' => 'session',
    ]);

    $session = Redis::connection('session');
    $session->set('sanabel_test_session_key', 'still here');

    Cache::store('redis')->put('sanabel_test_cache_key', 'cached', 60);
    expect(Cache::store('redis')->get('sanabel_test_cache_key'))->toBe('cached');

    Cache::store('redis')->flush();

    expect(Cache::store('redis')->get('sanabel_test_cache_key'))->toBeNull()
        // The session survived the flush, which is the whole point.
        ->and($session->get('sanabel_test_session_key'))->toBe('still here');

    $session->del('sanabel_test_session_key');
})->skip(fn () => ! redisReachable(), 'redis is not reachable here');

it('keeps a queued job out of the session keyspace', function () {
    config(['queue.default' => 'redis']);

    $session = Redis::connection('session');
    $session->set('sanabel_test_session_key', 'still here');

    // Raw, because an anonymous class cannot be serialised onto a queue.
    Queue::connection('redis')->pushRaw(json_encode(['job' => 'sanabel.test', 'data' => []]));

    // The job landed on the queue's keyspace, not the session's.
    expect(Redis::connection('queue')->keys('*'))->not->toBeEmpty()
        ->and($session->get('sanabel_test_session_key'))->toBe('still here');

    Redis::connection('queue')->flushdb();
    $session->del('sanabel_test_session_key');
})->skip(fn () => ! redisReachable(), 'redis is not reachable here');

it('needs no new package for any of it', function () {
    // CLAUDE.md §3 — no new packages without asking. The phpredis extension
    // is in the image already, so predis is not pulled in.
    expect(extension_loaded('redis'))->toBeTrue()
        ->and(config('database.redis.client'))->toBe('phpredis')
        ->and(file_exists(base_path('vendor/predis')))->toBeFalse();
});

it('still ships the database drivers, because the current host has no redis', function () {
    // The image must boot on what the association is actually paying for
    // today, which is one free container. Redis is an override, not a
    // requirement, until the hosting changes.
    $dockerfile = file_get_contents(base_path('Dockerfile'));

    expect($dockerfile)->toContain('QUEUE_CONNECTION=database')
        ->toContain('CACHE_STORE=database')
        ->toContain('SESSION_DRIVER=database');
});
