# Docker – backend

## Image choice
The production image (`Dockerfile`) uses **FrankenPHP in classic mode** (Caddy web server and PHP 8.3 in a
single binary), not Octane/worker mode:
- one process per container, no nginx + PHP-FPM pair to wire together and keep in sync;
- classic mode keeps the normal PHP request lifecycle (no state leaking between requests), which is the safe
  default for an attendance system; worker mode can be evaluated later if load tests (k6) require it;
- the `Server` and `X-Powered-By` headers are stripped (Caddyfile + `expose_php = Off` + `SecurityHeaders`
  middleware), dotfiles and source files are never served, only `public/index.php` executes.

Multi-stage: stage 1 installs Composer dependencies without dev packages; stage 2 is the runtime, runs as a
non-root user (uid 10001), listens on port `8080`, has a healthcheck on `/api/health`, and excludes tests,
docs and env files.

## One image, three roles
The entrypoint caches config/routes/events, then starts according to `CONTAINER_ROLE`:

| Role | Command | Instances |
|---|---|---|
| `app` (default) | HTTP API | 1..n (stateless) |
| `worker` | `php artisan queue:work` (emails, push, PDF) | 1..n |
| `scheduler` | `php artisan schedule:work` | **exactly 1** |

`RUN_MIGRATIONS=true` runs `php artisan migrate --force` before start; enable it on one role only (the
`app` service in compose). On a host without containers per role, the scheduler can also be a system cron:
`* * * * * php /app/artisan schedule:run`. Scheduled jobs are made idempotent with `scheduled_runs` (phase 1+),
so a duplicated scheduler would not double-run a job, but run only one anyway.

All configuration comes from environment variables (see `.env.example`); never bake secrets into the image.
Behind a load balancer set `TRUSTED_PROXIES` (IPs/CIDRs, or `*`).

## Local development
```
cp .env.example .env            # set DB_PASSWORD=attendance for compose
docker compose up --build       # API on http://localhost:8000, Postgres on 5432
docker compose exec app php artisan key:generate --show   # put the value in .env as APP_KEY
```
Compose starts `app`, `worker`, `scheduler` and `db` (Postgres 17, with an extra `attendance_test` database
for the test suite).
