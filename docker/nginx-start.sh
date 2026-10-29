#!/bin/sh
#
# nginx and php-fpm are started together by supervisor, which orders the spawn
# but knows nothing about readiness. Without this wait nginx holds the port open
# for the second or so php-fpm needs to bind 127.0.0.1:9000 and answers 502 to
# anything that arrives meanwhile — long enough for a platform health check
# landing in that window to fail an otherwise healthy deploy.
#
# The wait is bounded, and this is the whole point of the bound: an unbounded
# `until` loop is indistinguishable, from outside the container, from a healthy
# boot. php-fpm dies — out of memory on a small instance, a bad pool config —
# and nginx never binds the port, and the platform reports «no open ports
# detected» with nothing in the log to read. That is the deploy of 29 Oct 2026,
# and it is the second time this project has lost an afternoon to a silent
# wait (see the migration lock in docker/entrypoint.sh, T-79).
#
# Now it says what it was waiting for and exits, supervisor restarts it, and
# the message repeats in the deploy log until somebody reads it.
set -e

WAIT_SECONDS="${FPM_WAIT_SECONDS:-45}"
waited=0

until php -r 'exit(@fsockopen("127.0.0.1", 9000, $errno, $error, 1) ? 0 : 1);' 2>/dev/null; do
    waited=$((waited + 1))

    if [ "$waited" -ge "$((WAIT_SECONDS * 5))" ]; then
        echo "nginx did not start: php-fpm is not listening on 127.0.0.1:9000 after ${WAIT_SECONDS}s." >&2
        echo "The port this service is meant to serve on was never opened, because nginx waits" >&2
        echo "for php-fpm before binding it. Look above for php-fpm's own output: a pool that" >&2
        echo "cannot start, or a master killed for memory, prints there and nowhere else." >&2
        exit 1
    fi

    sleep 0.2
done

echo "php-fpm is up; nginx binding the service port."
exec nginx -g "daemon off;"
