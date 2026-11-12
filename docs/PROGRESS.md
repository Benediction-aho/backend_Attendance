# Progress – Backend (v2)

Updated by the agents at the end of every work session.

## Current phase
Phase 1 – Backend foundations: **backend complete (2026-11-12), ready for backend-security-tester** (report expected in `docs/reports/phase-1-security.md`).
Phase 0 findings fixed (see "Fixes applied" in `docs/reports/phase-0-security.md`), P0-01 waits for the owner.

### Phase 1 backend – what exists
- **Schema** (reversible migrations, PostgreSQL 17 in CI): `users` (roles, type, job title, status, profile, hashed code/password, code expiry; email unique case-insensitively among non-deleted accounts; CHECK constraints), `permissions` + `user_permissions` (19 tiers seeded), `settings` (versions with `effective_from`) + `setting_history`, `devices`, `personal_access_tokens.device_id`, `login_attempts` (lockout buckets), `login_codes` (hashed one-time codes and links), `activity_logs` (append-only, enforced by a trigger; only the retention purge may delete), `scheduled_runs` (unique run key), `notifications`.
- **Settings engine** (`App\Settings`): 41 typed settings with SPECS §3 / security.md defaults, effective dates (future changes never rewrite the past), history, cache. API `GET/PUT /admin/settings`, `GET /admin/settings/history` (super admin).
- **Roles and tiers**: `UserPolicy` (admins act on employees only, with the matching tier; staff accounts only by super admins; never on oneself), last-active-super-admin guard (409 `last_super_admin`).
- **Sign-up → approval → code → login → forced change**: public request (same answer for existing emails, rate limited), in-app + email notification to super admins and `approve_users` admins, approval emails a unique 10-char identification code (hashed, 48 h expiry), login with email + code, only `/me`, `/me/password`, `/auth/logout` until the code is replaced.
- **Super admin**: passwordless one-time 8-digit code per sign-in (hashed, single use, 10 min, 5 requests/hour), recovery link (single use, 30 min) sent automatically when locked and on request.
- **Lockout** (security.md §1): per known device 5/5/5 → 10 min, 20 min, locked; all unknown devices of an account share one bucket; unknown emails get identical answers and equal hashing cost; per-IP failure limit; whole account locked when 2 device buckets are locked (configurable); alerts to the people who may unlock (in-app + email) and email to the user; unlock by `block_users` admins (employees) or super admins (admins), optionally with a new code.
- **Sessions**: Bearer device tokens only, one per device, sliding expiry after 30 idle days (setting), revoked at once on block/lock/delete/new code/role change; password change signs out other devices.
- **Profile**: `PATCH /me` (names, birth date, address, phone, WhatsApp), photo upload to the private disk (`PRIVATE_FILES_DISK`: `local` in tests, `s3` = Supabase Storage online) with server-generated names and short-lived URLs, email change confirmed by link to the new address + alert to the old one (super admins confirm from both).
- **Admin user management**: list/search/filter, show, create directly (admins/super admins only by a super admin), approve/reject, block/unblock, unlock, new code, soft delete, permissions, role/promotion.
- **Activity log**: every sensitive action (sign-ins, failures, blocks, locks, unlocks, codes, account changes, permissions, roles, settings, profile, server and client errors, scheduled tasks); `GET /admin/activity-logs` with filters (user, actor, type, level, event prefix, dates), super admin or `view_logs` tier; no secrets in context; retention purge (`attendance:purge-logs`, hourly tick, once per day via `scheduled_runs`).
- **Notification centre**: `GET /notifications`, mark read / read all.
- **Install**: `php artisan attendance:create-super-admin` (flags or interactive, refuses when a super admin exists).
- **Emails**: English, queued with encrypted payloads, `log` mailer until the Resend key is available. Note: with the `log` mailer, codes appear in the server log – acceptable for local/test only.
- **API contract**: `docs/api/openapi.yaml` v0.1.0 documents every route (repository only).
- **Checks**: Pest 164 tests / 712 assertions green on PostgreSQL; Larastan level 8: 0 errors; Pint clean; migrations reversible.

### Authentication decision (P0-02) – for attendance-frontend
**Bearer device tokens only** (Sanctum personal access tokens, one per device). No cookie, no session, no CSRF route (`/sanctum/csrf-cookie` is not registered). The PWA generates a random `device_id` once, keeps it, sends it on login, stores the returned token on the device and sends `Authorization: Bearer <token>`. Only a 401 logs the user out (never a network error or 5xx). A 403 with `code: password_change_required` means: show the change-code screen. See `docs/api/openapi.yaml`.

### Next
- `backend-security-tester`: phase 1 review (auth flows, lockout, authorization per role, uploads, logs).
- Then phase 2 (frontend foundations) can start against the documented API.
- Backend items not yet done (no phase-1 blocker): self-service "forgot code/password" (see questions), emergency contact field (to confirm).

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
- Tests: Pest on PostgreSQL (`attendance_test`), 14 tests green (44 assertions); Larastan level 8 clean; Pint clean.
- Docker: multi-stage FrankenPHP image (classic mode), roles app/worker/scheduler, `docker-compose.yml` (app, worker, scheduler, Postgres 16). See `docs/DOCKER.md`. The image was **not built in this environment** (no Docker daemon); the first CI/deploy build must confirm it.
- CI: `.github/workflows/backend-ci.yml` (Postgres service, composer install, pint --test, phpstan, pest, migrate + rollback).

### How to run locally
See `README.md` (PHP + local PostgreSQL, or `docker compose up --build`).

## Phases

| Phase | Status | Security / UI / UX reports | Notes |
|---|---|---|---|
| 0 – Preparation | Backend skeleton done; online env pending | phase-0-security (fixes applied, re-test pending) | P0-01 owner action |
| 1 – Backend foundations | Backend done | – | Ready for backend-security-tester |
| 2 – Frontend foundations | Not started | – | |
| 3 – Attendance (manual) | Not started | – | |
| 4 – PWA, Face ID, notifications | Not started | – | |
| 5 – Tasks, clients, reports | Not started | – | |
| 6 – Dashboards and logs | Not started | – | |
| 7 – Quality and pilot | Not started | – | |
| 8 – Production deployment | Not started | – | Requires the owner's written approval below |

## Deployment (attendance-deploy, 2026-11-12)
- CI backend (`backend-ci`): job `test` (Pint, Larastan, Pest on Postgres 16, reversible migrations) green; new job `docker-build` builds the production image (no push) and smoke-tests `/api/health` + absence of `Server`/`X-Powered-By` headers. Run 43202465378 (commit adcbe30): both jobs green; the image builds, `/api/health` returns 200, no `Server`/`X-Powered-By` header.
- CI frontend (`frontend-ci`): green (lint, format, typecheck, unit tests, build).
- Supabase `ynspiijfjpumatmszmhx` (Attendance_Manager, eu-west-1, Postgres 17): ACTIVE_HEALTHY, security advisors: no findings. No migration applied (phase 1). CI now uses Postgres 17 like Supabase (phase 1).
- Vercel: **blocked** – the Vercel connection used by the agents has no access to the account scope `benediction-ahouanse-s-projects` (403). No project created.
- Backend test host: Render approved by the owner (account to create); Render procedure ready in `docs/DEPLOYMENT.md` (backend repo).

## Blocked / questions for the owner
Phase 1 business questions (safe default implemented, configurable where possible):
- **Q1 – Super admin sign-in**: SPECS §4 says "email and password", security.md §2 (decisions) says passwordless one-time code by email. Implemented: one-time code (security.md). Please confirm, then SPECS §4 will be aligned.
- **Q2 – Multi-device lockout guard**: implemented as "the whole account is locked when 2 device buckets are locked" (`lockout_multi_device_threshold`). An attacker using only unknown devices locks only unknown devices; the employee keeps signing in from her own phone. Confirm or choose another threshold.
- **Q3 – "Become super admin (promotion)" tier**: interpreted as an eligibility flag; only super admins can promote. Confirm.
- **Q4 – Personal data visibility**: admins holding an account-management tier (create/approve/block/delete users) see employees' birth date, address, phone, WhatsApp; other admins do not. Confirm, or name a dedicated tier.
- **Q5 – Rejected sign-up**: the request is removed silently (no email to the requester). Should the person be told?
- **Q6 – Forgot code/password** (SPECS §4 "également prévu"): not built yet. Proposal: "Forgot password" sends a single-use reset link by email (same lockout and rate limits); admins can already send a new code. Confirm.
- **Q7 – Monthly report time** ("soir, heure à définir"): default 19:00 (setting `monthly_report_time`).
- **Q8 – Emergency contact** in the profile ("à confirmer"): not added yet.
- **P0-01 – legacy v1 admin password in git history (owner action)**: a v1 admin password is readable in the repository history (`scripts/fixAdminPassword.js`, removed but still in history). Consider it compromised: change it on the v1 production admin and on any account that reuses it; never reuse it for v2. The history is not rewritten (force-push forbidden).
- **Vercel (owner action, still blocked 2026-11-12)**: after the connector re-authorization the Vercel API still returns `403 forbidden: Not authorized: Trying to access resource under scope "benediction-ahouanse-s-projects" (team_7rhgiQNsRJIXGdFxd45Eihlj)`; the connection lists no team. The re-authorization did not grant that scope. Either (a) re-authorize the Vercel connector in claude.ai Settings > Connectors and explicitly tick the scope "benediction-ahouanse-s-projects" in the Vercel consent screen, then ask the deploy agent to retry; or (b) create it yourself: Add New > Project > import `Benediction-aho/frontend_Attendance` (install the Vercel GitHub app on that repo if asked), framework Vite, build `npm run build`, output `dist`, env `VITE_API_URL=https://api.example.invalid/api` (Preview + Production). Preview deployments of `claude/upbeat-noether-f7defe` are then automatic.
- **Backend test host: Render approved by the owner.** Next step (owner): create the Render account (sign in with GitHub, give Render access to `Benediction-aho/backend_Attendance`), then tell us. Prepare, to be entered **only in Render secrets** (Environment tab of the service, never in chat or in the repository): the Supabase database password (Supabase dashboard > Project Settings > Database; reset it there if unknown), and later the Resend API key, Sentry DSN and VAPID keys. The deploy agent then creates the Docker web service, worker and cron following `docs/DEPLOYMENT.md` section 3.
- Accounts to provide (see `PLAN_DEVELOPPEMENT.md` section 8): Supabase, Resend + sending domain, Vercel, backend test host.

## Owner approval for production
_Not given yet._

## Log
- 2026-11-12 – Phase 1 backend complete (schema, settings, roles/tiers, sign-up/approval/codes, super admin OTP + recovery, device lockout, sessions, profile, admin user management, activity logs, install command); phase 0 findings P0-02/03/04 fixed; CI on Postgres 17.
- 2026-11-12 – Phase 0 backend: legacy v1 removed, Laravel 13 skeleton, security baseline, Docker, CI.
- Specifications, agents and workflow added to the repository.
- Switched from native app (Capacitor) to PWA; automatic check-in now based on office Wi-Fi detection.
- Wi-Fi detection and automatic check-in removed; check-in = GPS perimeter + Face ID (passkey); fixed-time reminder at 08:05.
