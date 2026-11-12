#!/bin/sh
# Role-based entrypoint: the same image runs the API, the queue worker or the scheduler.
#   CONTAINER_ROLE=app        HTTP API (default)
#   CONTAINER_ROLE=worker     php artisan queue:work (emails, push, PDF)
#   CONTAINER_ROLE=scheduler  php artisan schedule:work (one instance only)
# RUN_MIGRATIONS=true runs "migrate --force" before starting (enable on ONE role only).
set -e

php artisan config:cache
php artisan route:cache
php artisan event:cache

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
  php artisan migrate --force
fi

case "${CONTAINER_ROLE:-app}" in
  app)
    exec frankenphp run --config /etc/caddy/Caddyfile
    ;;
  worker)
    exec php artisan queue:work --tries=3 --backoff=10 --max-time=3600 --sleep=3
    ;;
  scheduler)
    exec php artisan schedule:work
    ;;
  *)
    exec "$@"
    ;;
esac
