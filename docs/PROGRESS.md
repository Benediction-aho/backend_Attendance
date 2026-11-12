# Progress – Backend (v2)

Updated by the agents at the end of every work session.

## Current phase
Phase 1 – Backend foundations: security review done (`docs/reports/phase-1-security.md`: 0 Critical/High, 3 Medium, 6 Low, 5 Info).
**All Medium and Low findings fixed (P1-01 … P1-09) plus Info P1-10, P1-11, P1-13, each with a regression test – ready for re-test by backend-security-tester** (see "Fixes applied" in the report).
Phase 0: re-test done by backend-security-tester (P0-02, P0-03, P0-04 confirmed fixed); P0-01 waits for the owner.

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
- **Phase 1 security fixes**: atomic attempt reservation (lockout, per-IP, sign-up, OTP, email change), constant dummy hash, current-password lockout that signs the session out, JSON 401 for guests, super admin codes per (account, IP) with up to 3 valid codes, email change without enumeration, activity log hardening (purge function, no TRUNCATE, owner/runtime DB roles, no FKs), recipients validation, super admin events hidden from admins by default, photo re-encoding. New settings: `otp_max_active_codes`, `email_change_requests_per_hour`, `logs_admins_see_super_admin_events`, `photo_max_dimension_px`; `ip_login_failures_limit` default 60 (unknown devices only).
- **Breaking for attendance-frontend**: `POST /me/email` no longer returns 422 `email_unavailable` (always 202); `PUT /me/password` and `POST /me/email` may return 401 `reauth_limit` (session signed out) or 429 `login_blocked`; `POST /me/email` may return 429 `too_many_requests`.
- **Deployment**: separate DB roles (`DB_APP_ROLE`, `DB_OWNER_USERNAME/PASSWORD`, `DEPLOYMENT.md` §2.1) – owner-run setup on Supabase before the first deploy.
- **Checks**: Pest 182 tests / 836 assertions green on PostgreSQL; Larastan level 8: 0 errors; Pint clean; migrations reversible.

### Authentication decision (P0-02) – for attendance-frontend
**Bearer device tokens only** (Sanctum personal access tokens, one per device). No cookie, no session, no CSRF route (`/sanctum/csrf-cookie` is not registered). The PWA generates a random `device_id` once, keeps it, sends it on login, stores the returned token on the device and sends `Authorization: Bearer <token>`. Only a 401 logs the user out (never a network error or 5xx). A 403 with `code: password_change_required` means: show the change-code screen. See `docs/api/openapi.yaml`.

### Next
- `backend-security-tester`: re-test of the phase 1 fixes (`tests/security/race_login.sh`, `burst.sh`, `timing_login.sh`; local role `attendance_app_test` for the P1-07 non-owner check).
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
| 0 – Preparation | Backend skeleton done; online env pending | phase-0-security (fixes re-tested OK) | P0-01 owner action |
| 1 – Backend foundations | Backend done, security fixes applied | phase-1-security (all Medium/Low fixed, re-test pending) | Ready for backend-security-tester re-test |
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
- **Q1 – Super admin sign-in**: resolved – one-time code by email (security.md §2); SPECS §4 aligned in both repositories (2026-11-12).
- **Q2 – Multi-device lockout guard**: implemented as "the whole account is locked when 2 device buckets are locked" (`lockout_multi_device_threshold`). An attacker using only unknown devices locks only unknown devices; the employee keeps signing in from her own phone. Confirm or choose another threshold.
- **Q3 – "Become super admin (promotion)" tier**: interpreted as an eligibility flag; only super admins can promote. Confirm.
- **Q4 – Personal data visibility**: admins holding an account-management tier (create/approve/block/delete users) see employees' birth date, address, phone, WhatsApp; other admins do not. Confirm, or name a dedicated tier.
- **Q5 – Rejected sign-up**: the request is removed silently (no email to the requester). Should the person be told?
- **Q6 – Forgot code/password** (SPECS §4 "également prévu"): not built yet. Proposal: "Forgot password" sends a single-use reset link by email (same lockout and rate limits); admins can already send a new code. Confirm.
- **Q7 – Monthly report time** ("soir, heure à définir"): default 19:00 (setting `monthly_report_time`).
- **Q8 – Emergency contact** in the profile ("à confirmer"): not added yet.
- **Q9 – Log visibility (P1-11)**: admins with `view_logs` no longer see entries about super admin accounts (sign-ins, IPs, email changes); switch `logs_admins_see_super_admin_events` on if they should.
- **P0-01 – legacy v1 admin password in git history (owner action)**: a v1 admin password is readable in the repository history (`scripts/fixAdminPassword.js`, removed but still in history). Consider it compromised: change it on the v1 production admin and on any account that reuses it; never reuse it for v2. The history is not rewritten (force-push forbidden).
- **Vercel (owner action, still blocked 2026-11-12)**: after the connector re-authorization the Vercel API still returns `403 forbidden: Not authorized: Trying to access resource under scope "benediction-ahouanse-s-projects" (team_7rhgiQNsRJIXGdFxd45Eihlj)`; the connection lists no team. The re-authorization did not grant that scope. Either (a) re-authorize the Vercel connector in claude.ai Settings > Connectors and explicitly tick the scope "benediction-ahouanse-s-projects" in the Vercel consent screen, then ask the deploy agent to retry; or (b) create it yourself: Add New > Project > import `Benediction-aho/frontend_Attendance` (install the Vercel GitHub app on that repo if asked), framework Vite, build `npm run build`, output `dist`, env `VITE_API_URL=https://api.example.invalid/api` (Preview + Production). Preview deployments of `claude/upbeat-noether-f7defe` are then automatic.
- **Backend test host: Render approved by the owner.** Next step (owner): create the Render account (sign in with GitHub, give Render access to `Benediction-aho/backend_Attendance`), then tell us. Prepare, to be entered **only in Render secrets** (Environment tab of the service, never in chat or in the repository): the Supabase database password (Supabase dashboard > Project Settings > Database; reset it there if unknown), and later the Resend API key, Sentry DSN and VAPID keys. The deploy agent then creates the Docker web service, worker and cron following `docs/DEPLOYMENT.md` section 3.
- Accounts to provide (see `PLAN_DEVELOPPEMENT.md` section 8): Supabase, Resend + sending domain, Vercel, backend test host.

## Owner approval for production
_Not given yet._

## Log
- 2026-11-12 – Phase 1 security fixes: P1-01 … P1-09, P1-10, P1-11, P1-13 fixed with regression tests (182 tests green); SPECS §4 aligned (super admin one-time code); ready for re-test.
- 2026-11-12 – Phase 1 backend complete (schema, settings, roles/tiers, sign-up/approval/codes, super admin OTP + recovery, device lockout, sessions, profile, admin user management, activity logs, install command); phase 0 findings P0-02/03/04 fixed; CI on Postgres 17.
- 2026-11-12 – Phase 0 backend: legacy v1 removed, Laravel 13 skeleton, security baseline, Docker, CI.
- Specifications, agents and workflow added to the repository.
- Switched from native app (Capacitor) to PWA; automatic check-in now based on office Wi-Fi detection.
- Wi-Fi detection and automatic check-in removed; check-in = GPS perimeter + Face ID (passkey); fixed-time reminder at 08:05.
