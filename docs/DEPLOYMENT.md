# Deployment

Status: **test environment in preparation (phase 0)**. Production (phase 8) only after the owner's written
approval in `docs/PROGRESS.md`. Secrets never go in the repository: only variable **names** appear here.

## 1. Environments

| Component | Test | Production (phase 8) |
|---|---|---|
| Frontend (React PWA) | Vercel project, preview deployments of the dev branch | Vercel, custom domain (also the WebAuthn RP ID) |
| API | Docker image on Render (proposed, awaiting owner approval), role `app` | Same image, same host type |
| Queue worker | Same image, role `worker` (Render background worker) | Same |
| Scheduler | Same image, role `scheduler` (exactly one instance) | Same |
| Database | Supabase project `ynspiijfjpumatmszmhx` (Postgres 17, eu-west-1) | Separate Supabase project |
| Files | Supabase Storage (S3 protocol, private bucket) | Same, separate bucket/project |
| CI | GitHub Actions: Pint, Larastan, Pest on Postgres, reversible migrations, `docker build` + `/api/health` smoke test | Same |

One image for all roles and environments (`Dockerfile`, see `docs/DOCKER.md`); only environment variables differ.

## 2. Required variables (names only)
Values live in the host's secret settings, never in Git.

- App: `APP_NAME`, `APP_ENV`, `APP_KEY`, `APP_DEBUG` (=false), `APP_URL`, `FRONTEND_URL`, `CORS_ALLOWED_ORIGINS`, `TRUSTED_PROXIES`, `MAX_REQUEST_BODY_KB` (=128), `LOG_CHANNEL` (=stderr), `LOG_LEVEL`
- Role: `CONTAINER_ROLE` (`app` | `worker` | `scheduler`), `RUN_MIGRATIONS` (true on `app` only), `PORT` (Render sets it; image default 8080)
- Database: `DB_CONNECTION` (=pgsql), `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_SSLMODE` (=require),
  `DB_APP_ROLE`, `DB_OWNER_USERNAME`, `DB_OWNER_PASSWORD` (separate database roles, see 2.1)
- Session/cache/queue: `SESSION_DRIVER`, `SESSION_SECURE_COOKIE` (=true), `SESSION_ENCRYPT`, `QUEUE_CONNECTION` (=database), `CACHE_STORE` (=database)
- Mail: `MAIL_MAILER` (=resend), `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`, `RESEND_API_KEY`
- Storage: `PRIVATE_FILES_DISK` (=s3 online: profile photos and reports, served only through short-lived pre-signed URLs), `FILESYSTEM_DISK`, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`, `AWS_ENDPOINT`, `AWS_USE_PATH_STYLE_ENDPOINT` (=true)
- Monitoring: `SENTRY_DSN`
- Web Push: `VAPID_SUBJECT`, `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, optional `WEBPUSH_ALLOWED_HOST_SUFFIXES` (see 2.4)
- Face ID: `WEBAUTHN_RP_ID`, `WEBAUTHN_RP_NAME`, `WEBAUTHN_ORIGINS` (see 2.3)
- Frontend (Vercel): `VITE_API_URL` (later also `VITE_SENTRY_DSN`, `VITE_VAPID_PUBLIC_KEY`)

### 2.1 Database roles (security report P1-07) — owner-run setup, once per environment
The application must not run as the role that owns the tables, otherwise it could erase the append-only
activity log (TRUNCATE, drop the trigger, delete with the purge flag).
1. In the Supabase SQL editor (as `postgres`), create the runtime role:
   `CREATE ROLE attendance_app LOGIN PASSWORD '<generated>' NOINHERIT;` (store the password in the host secrets only).
   With the Supavisor pooler its user name becomes `attendance_app.<project-ref>`.
2. Migration (owner) credentials: `DB_OWNER_USERNAME` / `DB_OWNER_PASSWORD` = the `postgres` user of the project.
   Runtime credentials: `DB_USERNAME` / `DB_PASSWORD` = `attendance_app`. Set `DB_APP_ROLE=attendance_app`.
3. `docker/entrypoint.sh` runs `migrate --database=pgsql_owner`. When `DB_APP_ROLE` is set, the migration
   `2026_11_13_100000_harden_activity_logs` grants the runtime role SELECT/INSERT/UPDATE/DELETE on the tables
   (plus default privileges for future tables) but only SELECT/INSERT on `activity_logs`. Since P1-15 the runtime
   role has **no EXECUTE** on `purge_activity_logs()` (migration `2026_11_14_100000_purge_logs_floor` revokes it).
   The scheduled `attendance:purge-logs` command runs the purge on the `pgsql_owner` connection whenever
   `DB_OWNER_USERNAME` (or `DB_OWNER_URL`) is set, so the scheduler/worker container needs the owner credentials;
   the web container does not use them. The function itself also refuses to delete entries younger than a hard
   12-month floor (constant in the function, not a setting), and `log_retention_months` cannot be set below 12.
   The delete trigger accepts deletions only from the table owner, so the runtime role cannot purge by setting the flag.
   TRUNCATE is refused by a statement trigger for everyone.
4. If the migration ran before `DB_APP_ROLE` was set, re-run its grants by hand (statements are in the migration).
Check: as `attendance_app`, `DELETE FROM activity_logs` → permission denied; `TRUNCATE activity_logs` → refused;
`SELECT purge_activity_logs(now())` → permission denied.
Local development uses one role for both (tests cover the non-owner case with the local role `attendance_app_test`:
`CREATE ROLE attendance_app_test NOLOGIN; GRANT attendance_app_test TO attendance;` as the postgres superuser).

### 2.2 Shared office IP (P1-10)
Everyone in the office signs in from one public IP. The per-IP limit (`ip_login_failures_limit`, default 60 per
`ip_login_window_minutes` = 15) only counts sign-in attempts from unknown devices and unknown emails; known devices
(a phone that already signed in) are never refused by it. Raise it in Settings if many new phones are enrolled at once.

### 2.3 Face ID (WebAuthn passkeys) – RP ID
- `WEBAUTHN_RP_ID` **must be the final domain of the PWA** (e.g. `attendance.example.com`, no scheme, no port). Passkeys are
  bound to the RP ID by the phone: if it changes after go-live, every employee must register Face ID again (and every new
  registration after the first one needs an admin approval). The test environment uses its own domain (its passkeys do not work in production).
- `WEBAUTHN_ORIGINS`: exact origin(s) of the PWA, comma-separated (`https://attendance.example.com`). HTTPS is mandatory except `http://localhost`.
- The API only stores public keys; no biometric data is ever received.
- Kill switch: setting `faceid_required_for_checkin` (super admin configuration page). Off = check-in without Face ID (emergency only, logged as a setting change).

### 2.4 Web Push (VAPID)
- Generate the key pair once per environment: `php artisan attendance:vapid-keys`, then store `VAPID_PRIVATE_KEY` in the host secrets
  only; `VAPID_PUBLIC_KEY` goes to the API and to the frontend (`VITE_VAPID_PUBLIC_KEY`, also readable at `GET /api/push/public-key`).
  Changing the keys invalidates all subscriptions (users re-subscribe automatically at next sign-in).
- `VAPID_SUBJECT`: `mailto:` address of the operator.
- Pushes are sent by the **worker** (queue). Without keys, pushes are skipped; in-app notifications are always stored.
- The worker must reach the push services over HTTPS (fcm.googleapis.com, *.push.apple.com, updates.push.services.mozilla.com, *.notify.windows.com).

### First super admin (once, after the first deployment)
There is no public setup page. From a shell in the `app` container: `php artisan attendance:create-super-admin --email=… --first-name=… --last-name=…`
(refuses to run when a super admin already exists). The super admin then signs in with the email address and a one-time code sent by email.
Note: the API is Bearer-token only and sets no cookie; `SESSION_*` variables are unused by the API.

## 3. Backend test host on Render (ready once the owner approves Render)

Prerequisites from the owner: a Render account connected to GitHub with access to `backend_Attendance`;
the Supabase database password; Resend key; Sentry DSN.

### 3.1 Supabase connection (pooler)
Render does not provide IPv6 egress reliably and the direct host `db.<ref>.supabase.co` is IPv6-only, so use
the **Supavisor pooler** (Supabase dashboard > Connect):
- `DB_HOST` = `aws-0-eu-west-1.pooler.supabase.com` (copy the exact host from the dashboard)
- `DB_USERNAME` = `postgres.ynspiijfjpumatmszmhx`
- `DB_DATABASE` = `postgres`, `DB_SSLMODE` = `require`
- **Session mode, port 5432** for the `app`, `worker` and `scheduler` services and for migrations
  (Laravel uses prepared statements and advisory locks; transaction mode port 6543 breaks them).
- Keep the total connection count under the plan's pooler limit (each PHP process = 1 connection).

### 3.2 Services (Blueprint or dashboard), all "Docker" runtime, region Frankfurt (closest to eu-west-1)
1. **Web service** `attendance-api`
   - Repository `backend_Attendance`, branch = dev branch for test, Dockerfile path `./Dockerfile`.
   - Env: all variables of section 2, plus `CONTAINER_ROLE=app`, `RUN_MIGRATIONS=true`.
   - Health check path: `/api/health`.
   - `TRUSTED_PROXIES=*`: Render's load balancer terminates TLS and its internal IPs are not fixed. In this
     application `*` means **the immediate peer only** (`REMOTE_ADDR`, i.e. Render's proxy), not "every
     address" as in stock Laravel (REL-05): the client IP is the right-most `X-Forwarded-For` entry, the one
     Render's proxy appends; anything a client puts on the left is ignored. Only `X-Forwarded-For` and
     `X-Forwarded-Proto` are read (`X-Forwarded-Host/Port/Prefix` never are), and every generated URL is built
     from `APP_URL` (forced root URL and scheme). If another proxy/CDN is ever put in front of Render, list its
     ranges explicitly, e.g. `TRUSTED_PROXIES=REMOTE_ADDR,<cdn-cidr-1>,<cdn-cidr-2>`. Verify with section 3.4.
   - Auto-deploy on push: on.
2. **Background worker** `attendance-worker`: same repo/Dockerfile, `CONTAINER_ROLE=worker`, `RUN_MIGRATIONS=false`.
   Render restarts it on each deploy (picks up the new code).
3. **Scheduler**: either a second background worker `attendance-scheduler` with `CONTAINER_ROLE=scheduler`
   (exactly 1 instance, recommended: `schedule:work` runs every minute), or a Render **Cron Job** with schedule
   `* * * * *` and command `php artisan schedule:run` (`CONTAINER_ROLE` irrelevant). Never both.
4. Use an **Environment Group** for the shared variables, attached to all three services.
5. Custom domain (test): optional; CORS `CORS_ALLOWED_ORIGINS` must list the Vercel preview origin.
6. Once live, set the frontend `VITE_API_URL` on Vercel to `https://<api-host>/api` and redeploy.

Private files (REL-07): `PRIVATE_FILES_DISK=s3` (Supabase Storage, private bucket) is set in `render.yaml`;
enter `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` and `AWS_BUCKET` on each service. With `local`, files
uploaded on the web container would be invisible to the worker and the scheduler and lost on every deploy.
Exception: the free-plan setup of section 3.3 runs a single API container (no worker, no scheduler process), so
`local` "works" there, but files are still lost on every deploy/restart; use `s3` as soon as the bucket exists.

Free-plan note: free web services sleep after inactivity and workers are not free; the scheduler and
reminders need an always-on paid instance (Starter) for meaningful tests.

## 4. Deploy procedure
1. CI green on the commit (tests + Docker build).
2. Push to the deploy branch; Render builds the image once per service.
3. `app` runs `migrate --force` at start. Migrations must be **expand/contract** (additive first, remove
   columns in a later release) so the previous image still works during the rollout.
4. Render switches traffic after `/api/health` passes (zero-downtime).

## 5. Rollback
1. Render dashboard > service > Events > previous successful deploy > **Rollback** (redeploys the previous
   image without rebuilding). Do it for `app`, `worker` and scheduler.
2. If the faulty release ran a destructive migration, restore from backup (section 6) instead; additive
   migrations need no rollback.
3. Frontend: Vercel > Deployments > previous deployment > **Instant Rollback**.

## 6. Backups and restore
- Supabase daily backups (paid plan; PITR optional). Test env: free plan has no downloadable backups, so a
  nightly `pg_dump` job will be added in phase 7.
- Restore drill (to document with real timings in phase 7): restore into a new Supabase project or a branch,
  point a staging `app` at it, run `/api/health` and a smoke login.

## 7. Monitoring
- Sentry for backend (`SENTRY_DSN`) and frontend.
- Uptime check (e.g. Better Stack / UptimeRobot) on `https://<api-host>/api/health` every minute.
- Scheduler heartbeat: `scheduled_runs` table (phase 1+) plus an alert when a job has not run in time.

### 3.3 Free-plan test setup (no worker, no scheduler process)
For tests on Render's free plan (owner decision; production will move to the company's own server):
- `QUEUE_CONNECTION=sync` on the API: emails and pushes are sent during the request (no worker needed).
- Scheduler: an external cron service (e.g. cron-job.org, free) calls `POST /api/ops/scheduler-tick` every
  minute with header `X-Scheduler-Token: <SCHEDULER_TICK_TOKEN>`. The endpoint runs `schedule:run` once; every
  task is idempotent and catches up, so missed or duplicate ticks are safe. Without `SCHEDULER_TICK_TOKEN` the
  endpoint answers 404; any other method, a wrong token and more than 10 calls per minute from one IP also get 404
(REL-09). The per-minute call also keeps the free service and the free Supabase project awake.

### 3.4 Client IP and URL check (REL-05) – run after the first deploy and after any infrastructure change
1. From a known address, send two requests with a forged header:
   `curl -s -o /dev/null -X POST https://<api-host>/api/auth/login -H 'X-Forwarded-For: 203.0.113.77' -H 'Content-Type: application/json' -d '{"email":"nobody@example.com","password":"x","device_id":"check-ip-000000000001"}'`
2. In `activity_logs` (latest `auth.login_failed` rows), the IP must be **your real public address**, never
   `203.0.113.77`. If it is the forged value, the proxy overwrites nothing: set `TRUSTED_PROXIES` to an empty value
   and open a question. If it is an address of Render/Cloudflare shared by everyone, a second proxy hop exists:
   list its ranges in `TRUSTED_PROXIES` (with `REMOTE_ADDR`).
3. REL-19: repeat step 1 with **two** forged lines (`-H 'X-Forwarded-For: 203.0.113.77' -H 'X-Forwarded-For: 203.0.113.78'`): the recorded IP must still be your real address.
4. `curl -s -H 'X-Forwarded-Host: evil.example' https://<api-host>/api/health` changes nothing; signed file links
   always start with `APP_URL`.
5. Record the date and result here.

### 3.5 Request body limit (REL-06)
`MAX_REQUEST_BODY_KB` (default 128) refuses any non-multipart body above that size with 413 before it is parsed.
Uploads (weekly PDF, profile photo) are multipart and keep their own limits (settings + `post_max_size`).

### 3.6 Super admin locked out without a known device (REL-20) – out-of-band procedure
The recovery link is capped per hour (`recovery_links_sent_per_hour`); only a device the super admin already used
bypasses the cap. If an attacker keeps the cap used up while the super admin has lost every known device:
1. **Recommended: keep at least two active super admins.** The other super admin opens Users > the account >
   **Unlock** (`POST /api/admin/users/{id}/unlock`); the locked super admin then signs in with a new one-time code.
2. With a single super admin, the server owner (Render shell / server console, never by e-mail or chat) unlocks the
   account after checking the person's identity by phone or in person:
   `php artisan tinker --execute="app(App\Services\Auth\LockoutService::class)->unlockAccount(App\Models\User::findByEmail('<email>'))"`
   then writes down who did it and why (the unlock itself is not logged from tinker).
   Look at the `auth.recovery_contention` critical entries to identify the attacking addresses.
3. Afterwards, add a second super admin (promote an eligible admin) so step 1 is available next time.

### 3.7 File URLs and the frontend CSP (REL-23)
`App\Services\FileUrls::temporaryUrl()` decides what the browser receives for photos and PDFs:
- `PRIVATE_FILES_DISK=local`: an **API-signed URL** on `APP_URL` (`/api/files/...?...&signature=`), served by the API.
- `PRIVATE_FILES_DISK=s3` (Render, REL-07): a **direct pre-signed Supabase Storage URL**, e.g.
  `https://<project-ref>.storage.supabase.co/storage/v1/s3/...` (host of `AWS_ENDPOINT`), not proxied by the API.
So the frontend CSP (`frontend_Attendance/vercel.json`, owned by attendance-frontend) must allow that storage origin in
`img-src` (profile photos) and `connect-src` (PDF downloads fetched by the app), and list the **final API origin** in
`connect-src`/`img-src` once the production domain is known. Today: `https://ynspiijfjpumatmszmhx.storage.supabase.co`
and `https://attendance-api-1xgl.onrender.com`. Update both whenever `AWS_ENDPOINT` or `APP_URL` changes.
