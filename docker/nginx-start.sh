#!/bin/sh
#
# nginx and php-fpm are started together by supervisor, which orders the spawn
# but knows nothing about readiness. Without this wait nginx holds the port open
# for the second or so php-fpm needs to bind 127.0.0.1:9000 and answers 502 to
# anything that arrives meanwhile — long enough for a platform health check
# landing in that window to fail an otherwise healthy deploy.
set -e

until php -r 'exit(@fsockopen("127.0.0.1", 9000, $errno, $error, 1) ? 0 : 1);' 2>/dev/null; do
    sleep 0.2
done

exec nginx -g "daemon off;"
