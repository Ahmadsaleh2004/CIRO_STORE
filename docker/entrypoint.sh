#!/bin/sh
# ══════════════════════════════════════════════════════════════
# Cairo Store — container entrypoint
# ══════════════════════════════════════════════════════════════
#
# The image serves Apache on port 80, which is right for docker-compose and wrong for
# every managed platform. Railway, Render, Fly and Cloud Run all inject a $PORT and route
# only to that port; a container that keeps listening on 80 is reported as unhealthy and
# gets killed before it serves a request. There is no way to fix this in the Dockerfile
# alone, because $PORT is known at run time, not at build time.
#
# So the port is rewritten here, on every start, from whatever the platform provides —
# falling back to 80 so `docker compose up` behaves exactly as it did before this file
# existed.
set -e

PORT="${PORT:-80}"

# Both files matter. ports.conf decides what Apache binds; the VirtualHost decides which
# requests it answers. Changing one and not the other yields a server that listens on the
# right port and returns 404 for everything.
sed -ri "s/^Listen [0-9]+$/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/*.conf

# ServerName silences the "could not reliably determine the server's fully qualified
# domain name" warning that otherwise opens every platform's log and makes a healthy boot
# look like a failure.
if ! grep -q '^ServerName' /etc/apache2/apache2.conf; then
    echo "ServerName localhost" >> /etc/apache2/apache2.conf
fi

echo "[entrypoint] Apache will listen on port ${PORT}"

exec "$@"
