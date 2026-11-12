# Progress – Backend (v2)

Updated by the agents at the end of every work session.

## Current phase
Phase 0 – Preparation: **backend skeleton done** (2026-11-12). Online test environment (Supabase DB, test host) still waiting for the owner's accounts and the `attendance-deploy` agent.

Backend phase 0 is **ready for backend-security-tester** (security baseline: headers, generic JSON errors, neutral cookie names, trusted proxies).

### Legacy v1
The legacy Node/Mongo code was removed from the development branch. It remains on `main` at commit
`7e53efcbb392bb2ca8ec8dc5666e55939731b325` (the `legacy-v1` tag could not be pushed from this environment; the owner can create it with
`git tag legacy-v1 7e53efcbb392bb2ca8ec8dc5666e55939731b325 && git push origin legacy-v1`).

### Phase 0 backend – what exists
- Laravel 13 (PHP 8.3), API only, Sanctum installed (device tokens, no stateful SPA domains), PostgreSQL default, timezone `Africa/Accra`, locale `en`.
- Queue: database driver; mail: `log` by default, Resend native transport ready (`MAIL_MAILER=resend` + `RESEND_API_KEY`, not set).
- Security baseline: `App\Http\Middleware\SecurityHeaders` (removes `X-Powered-By`/`Server`, adds nosniff, DENY, no-referrer, CSP, HSTS on HTTPS, `no-store` on API), session cookie `sid`, cache prefix `att-cache-`, every error rendered as generic JSON by `App\Exceptions\ApiExceptionRenderer` (details only when `APP_DEBUG=true`), no web routes (all unknown paths = JSON 404), Laravel's `/up` page removed, `TRUSTED_PROXIES` env.
- `GET /api/health` → `{"status":"ok"}`.
- API contract: `docs/api/openapi.yaml` (repository only, not served).
- Tests: Pest on PostgreSQL (`attendance_test`), 15 tests green; Larastan level 8 clean; Pint clean.
- Docker: multi-stage FrankenPHP image (classic mode), roles app/worker/scheduler, `docker-compose.yml` (app, worker, scheduler, Postgres 16). See `docs/DOCKER.md`. The image was **not built in this environment** (no Docker daemon); the first CI/deploy build must confirm it.
- CI: `.github/workflows/backend-ci.yml` (Postgres service, composer install, pint --test, phpstan, pest, migrate + rollback).

### How to run locally
See `README.md` (PHP + local PostgreSQL, or `docker compose up --build`).

### Next
- `backend-security-tester`: phase 0 baseline review.
- Phase 1 – backend foundations (schema, settings engine, roles/permissions, auth flows, device lockout, logs). The default `users` table from the skeleton will be replaced by the phase 1 schema.

## Phases

| Phase | Status | Security / UI / UX reports | Notes |
|---|---|---|---|
| 0 – Preparation | Backend skeleton done; online env pending | – | Ready for backend-security-tester |
| 1 – Backend foundations | Not started | – | |
| 2 – Frontend foundations | Not started | – | |
| 3 – Attendance (manual) | Not started | – | |
| 4 – PWA, Face ID, notifications | Not started | – | |
| 5 – Tasks, clients, reports | Not started | – | |
| 6 – Dashboards and logs | Not started | – | |
| 7 – Quality and pilot | Not started | – | |
| 8 – Production deployment | Not started | – | Requires the owner's written approval below |

## Blocked / questions for the owner
- Accounts to provide (see `PLAN_DEVELOPPEMENT.md` section 8): Supabase, Resend + sending domain, Vercel, backend test host.

## Owner approval for production
_Not given yet._

## Log
- 2026-11-12 – Phase 0 backend: legacy v1 removed, Laravel 13 skeleton, security baseline, Docker, CI.
- Specifications, agents and workflow added to the repository.
- Switched from native app (Capacitor) to PWA; automatic check-in now based on office Wi-Fi detection.
- Wi-Fi detection and automatic check-in removed; check-in = GPS perimeter + Face ID (passkey); fixed-time reminder at 08:05.
