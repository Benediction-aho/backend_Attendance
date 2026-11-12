# Phase 1 – Security report (backend foundations)

Date: 2026-11-12 · Branch `claude/upbeat-noether-f7defe` (commit `3c473a2`) · Tester: backend-security-tester
Environment: local only. `php -S 127.0.0.1:8181` with 16 workers (`PHP_CLI_SERVER_WORKERS=16`), `APP_ENV=production APP_DEBUG=false`, `QUEUE_CONNECTION=sync`, `MAIL_MAILER=log` (codes and links read from `storage/logs/laravel.log`, no real email sent), local PostgreSQL database `attendance` (`migrate:fresh`). Some time-based states were fast-forwarded by editing `login_attempts.blocked_until` / `login_codes.expires_at` in the local database.
Scripts: `tests/security/` (`lib.sh`, `authz_matrix.sh`, `race_login.sh`, `timing_login.sh`, `burst.sh`).

No Critical or High finding. 3 Medium, 6 Low, 5 Info.

## Findings

### P1-01 – Lockout and per-IP limit can be raced: blocks skipped, more guesses than the policy allows — **Medium**
- Files: `app/Services/Auth/LoginService.php` (`attempt`), `app/Services/Auth/LockoutService.php` (`ensureCanAttempt` / `recordFailure`).
- Cause: check-then-act. `ensureCanAttempt()` is a plain `SELECT`, the password is checked (bcrypt, ~200 ms), and only then `recordFailure()` takes the row lock. The per-IP limiter has the same pattern (`tooManyAttempts` first, `hit` after a failure). The `throttle:signup` limiter also lets a few extra requests through under a burst.
- Repro:
  ```
  tests/security/race_login.sh emp1@test.local 60     # 60 parallel wrong guesses, unknown devices
  →  14 × 401 (password actually checked), 15 × 429, 31 × 423
  login_attempts: unknown_device series=3 locked
  tests/security/burst.sh /auth/login '{"email":"burst{}@test.local","password":"x","device_id":"burst-device-{}-aaaaaaaa"}' 200
  →  45 × 401 although ip_login_failures_limit = 30
  tests/security/burst.sh /auth/register '{...reg{}@test.local...}' 200  → 7 accounts created, limit 5/hour
  ```
- Observed: in one second, 14–15 guesses are checked and the 10-minute and 20-minute blocks are skipped (the bucket goes straight to *locked*). The number of guesses checked is bounded by the server's worker count, not by the policy (5 per series). This breaks security.md §1 "pendant un blocage, toute tentative est refusée sans vérifier le code". Failures that arrive after the lock still increment the counters.
- Expected: at most 5 secrets checked per series, whatever the concurrency.
- Impact: low on its own (10-character codes, 8-digit OTPs, strong passwords), but it scales with production concurrency (FrankenPHP workers × instances) and makes the per-IP limit unreliable.
- Fix: reserve the attempt **before** checking the secret, atomically. For example, in one transaction: `insertOrIgnore` the bucket row, `SELECT … FOR UPDATE`, refuse if blocked/locked, increment `failures` (pending attempt), commit; check the secret; on success reset the bucket. Alternatively, use an atomic counter (`Cache::increment`/`RateLimiter::hit` *before* the check) and refuse when the returned value exceeds the limit. Do the same for the IP limiter (`RateLimiter::attempt` or hit-first), and use an atomic cache store (Redis or a database row with `FOR UPDATE`) for the `signup` throttle. Add a test that runs parallel requests (or simulates the interleaving).

### P1-02 – Timing oracle on `/auth/login`: unknown emails and super admins are about twice as slow as employees — **Medium**
- File: `app/Services/Auth/LoginService.php` (`dummyCheck`).
- Cause: `self::$dummyHash ??= Hash::make(...)` is cached in a static property, which only lasts for one PHP request. Each dummy check therefore runs **two** bcrypt operations (`make` + `check`), while a real account runs only one.
- Repro: `PGPASSWORD=… tests/security/timing_login.sh emp2@test.local 15` and the same with `sa2@test.local` (it resets buckets between samples):

  | Case | Median |
  |---|---|
  | existing employee/admin, wrong password | 0.24 s |
  | unknown email | 0.44 s |
  | existing super admin with no active OTP | 0.44 s |
  | existing super admin with an active OTP | ~0.24 s |
- Observed: one request is enough to tell whether an email is an employee/admin account (fast) or unknown/super admin (slow), and whether a super admin currently has an unexpired code. This breaks security.md §1 "message identique que l'email existe ou non" in spirit (the messages are identical, the timing is not).
- Fix: use a constant precomputed bcrypt hash (generated once and stored in config or as a class constant, with the same cost as `config('hashing.bcrypt.rounds')`) and only call `Hash::check` against it. Then every path costs exactly one bcrypt verification. Add a test asserting that `Hash::make` is not called on the login path.

### P1-03 – Current-password checks are not rate limited (`PUT /me/password`, `POST /me/email`) — **Medium**
- Files: `app/Http/Controllers/MeController.php` (`password`), `app/Http/Controllers/ProfileController.php` (`requestEmailChange`).
- Repro (with a valid employee token):
  ```
  for i in $(seq 1 40); do api POST /me/email $T '{"email":"z@test.local","current_password":"guess'$i'"}'; done  → 40 × 422
  for i in $(seq 1 30); do api PUT /me/password $T '{"current_password":"guess'$i'",...}'; done             → 30 × 422
  ```
- Observed: only the global API limit applies (120/min per user = 7,200 guesses/hour). The failures are logged but never block.
- Impact: someone who gets hold of a session (a phone left unlocked, a shared device, a leaked token) can brute-force the personal password. They can then change it, which signs every other device out and turns a temporary session into a permanent takeover.
- Fix: count wrong current-password answers like sign-in failures (same 5/5/5 buckets on the current device, or a dedicated limiter such as 5 per 15 min per user). After the limit, revoke the current token and alert the user.

### P1-04 – Unauthenticated request without `Accept: application/json` → HTTP 500 and a `server.error` row in the append-only log — **Low**
- Endpoints: every `auth:sanctum` route, e.g. `GET /api/me`, `GET /api/admin/users`.
- Repro: `curl -si http://localhost:8181/api/me` (no `Accept` header, no or invalid token) → `500 {"message":"Something went wrong. Please try again later."}`. `activity_logs` gets `server.error` with `{"exception":"Symfony\\Component\\Routing\\Exception\\RouteNotFoundException","path":"api/me"}`. `laravel.log` gets `Route [login] not defined` with a trace.
- Observed: the `Authenticate` middleware tries to redirect to the `login` route, which does not exist. Any anonymous client can generate 500s and fill the append-only activity log (≈120/min per IP), which also creates noise in error monitoring.
- Expected: `401 {"message":"Unauthenticated."}`.
- Fix: in `bootstrap/app.php`, `$middleware->redirectGuestsTo(fn () => null)` (or `Authenticate::redirectUsing(fn () => null)`), or force `Accept: application/json` on API requests in a middleware. Optionally, do not write anonymous `server.error` entries to `activity_logs` without a limit.

### P1-05 – Anyone can exhaust a super admin's one-time-code quota and cancel codes in transit — **Low**
- File: `app/Services/Auth/SuperAdminCodeService.php` (`request`).
- Repro: 5 × `POST /auth/super-admin/code {"email":"sa2@test.local"}` from any IP. The super admin's own request is then silently ignored for an hour (`202` but no email: 10 requests → 2 emails sent after 3 earlier ones). Each new code also invalidates the previous one, so an attacker can cancel the code the real super admin is about to type.
- Observed: the per-account limit (5/hour) is shared by everyone, so an anonymous attacker who knows the email can keep the super admin from signing in (and spam them with up to 5 emails/hour) with 5 requests per hour from one IP.
- Fix: key the per-account counter by (account, IP) or (account, device), keep a higher global per-account ceiling as well, and do not invalidate an unexpired code when the request comes from a different IP/device than the one that requested it (or allow 2–3 valid codes at once). Alert the super admin when the quota is hit.

### P1-06 – Email change: account enumeration and unlimited confirmation emails to any address — **Low**
- Endpoint: `POST /me/email`.
- Repro: `{"email":"sa1@test.local",...}` → `422 email_unavailable`. `{"email":"nobody77@test.local",...}` → `202`. Ten requests to `victim@test.local` → 10 emails delivered to that address.
- Observed: any employee can test whether an email has an account (including super admins). They can also send unlimited "confirm your new email" messages to any third-party address, which hurts the Resend sender reputation.
- Fix: answer `202` with the same message in both cases and send nothing (or a "someone tried to use your address" notice to the existing account). Add a per-user limiter (e.g. 3 requests/hour) on `/me/email`.

### P1-07 – `activity_logs` append-only protection can be bypassed by the application database role — **Low**
- File: `database/migrations/2026_11_12_200000_create_phase1_tables.php`.
- Repro (local psql as the app role `attendance`, inside `BEGIN … ROLLBACK`):
  - `UPDATE activity_logs …` → `ERROR: activity_logs is append-only` (good). `DELETE …` → refused (good).
  - `SET LOCAL attendance.allow_log_purge='on'; DELETE FROM activity_logs WHERE id=1;` → `DELETE 1`.
  - `TRUNCATE activity_logs CASCADE;` → succeeds (row triggers do not fire on TRUNCATE).
  - The app role owns the table, so it can also `DROP TRIGGER`.
- Impact: if an attacker ever gets SQL execution as the app (an SQL injection, a stolen DB URL), they can erase their traces. No such path was found through the HTTP API.
- Fix: add a `BEFORE TRUNCATE … FOR EACH STATEMENT` trigger. In test/prod, run the app as a role that does not own `activity_logs` (owner = migration role; app role gets `INSERT, SELECT` only, and the purge goes through a `SECURITY DEFINER` function owned by the migration role). Alternatively, send a copy of security events to an external sink (Sentry/log drain).

### P1-08 – Append-only trigger blocks `ON DELETE SET NULL` from devices/users — **Low** (reliability)
- Repro: `BEGIN; DELETE FROM devices WHERE id = (SELECT device_id FROM activity_logs WHERE device_id IS NOT NULL LIMIT 1); ROLLBACK;` → `ERROR: activity_logs is append-only`.
- Observed: the foreign keys `activity_logs.device_id` / `user_id` / `actor_id` are `nullOnDelete()`, which runs an `UPDATE` on `activity_logs` that the trigger refuses. Deleting a device (phase 4, Face ID device revocation) or force-deleting a user will fail with a 500.
- Fix: use `ON DELETE NO ACTION` and never hard-delete referenced rows (soft-delete devices). Alternatively, store `device_id`/`user_id` in logs without a foreign key. Add a test that deletes a device which has log entries.

### P1-09 – `monthly_report_recipient_ids` accepts any user (employees, deleted users) and cannot be emptied — **Low**
- Endpoint: `PUT /admin/settings`.
- Repro: `{"values":{"monthly_report_recipient_ids":[4]}}` (4 = an employee) → `200`. `{"values":{"monthly_report_recipient_ids":[]}}` → `422 "… field is required."`
- Observed: the label says "admin ids", but the rule is `exists:users,id`, which also matches employees and soft-deleted accounts. The monthly report (everyone's attendance) could therefore be sent to an employee. Once set, the list cannot be cleared.
- Fix: validate each id against active admins/super admins (`Rule::exists('users','id')->where(role in admin/super_admin, deleted_at null)`) and re-check when sending. For list settings, use `present|array` instead of `required`.

### P1-10 – Per-IP sign-in limit also blocks correct credentials from a shared office IP — **Info**
- Repro: after 30 failures from one IP, a correct login → `429 ip_limited` (`retry_after_seconds` ≈ 900).
- Everyone in the office shares one public IP behind NAT. 30 typos in 15 minutes (or one person scripting), and nobody in the office can sign in for 15 minutes. Consider exempting known devices from the IP limit, or counting only failures for unknown devices/unknown emails per IP.

### P1-11 – Admins with `view_logs` see super admin security events — **Info**
- `GET /admin/activity-logs` returns entries for every account, including super admins' sign-ins, IPs, user agents and email-change targets. Confirm with the owner that this is intended for the `view_logs` tier.

### P1-12 – External attacker can only lock the "unknown devices" bucket, so the multi-device whole-account lock never triggers from outside — **Info**
- Verified behaviour: 15 failures from rotating `device_id`s lock only the shared unknown bucket. The owner's known device still signs in. The whole account locks only when a *known* device bucket also locks. This matches the implementation note Q2 in `PROGRESS.md`. Owner confirmation is still pending.

### P1-13 – Profile photos are stored as uploaded (polyglot content and EXIF metadata kept) — **Info**
- A valid PNG with PHP appended (`ok.png + shell.php`) is accepted and served back byte for byte as `image/png` with `nosniff`. That is not executable, but EXIF (GPS location of where the photo was taken) is also kept. Re-encode images with GD/Imagick on upload (this also normalises the size). Also note that the local PHP `upload_max_filesize` (2M) is lower than `photo_max_kb` (5 MB). `docker/php.ini` (10M) is fine.

### P1-14 – Sign-up timing differs slightly between new and existing emails — **Info**
- Median 0.04 s (new email: insert + approver notifications) vs 0.03 s (existing email). It is barely measurable and the endpoint allows 5 requests/hour/IP, so the risk is low. With queued mail in production the gap is a few DB inserts. Optional: do the notification work in a queued job in both cases.

## Summary

| ID | Title | Severity | Status |
|---|---|---|---|
| P1-01 | Lockout / IP limit / sign-up throttle race (check-then-act) | Medium | Open |
| P1-02 | Login timing oracle (double bcrypt on dummy path) | Medium | Open |
| P1-03 | Current-password checks not rate limited | Medium | Open |
| P1-04 | Unauthenticated request without `Accept` → 500 + log flooding | Low | Open |
| P1-05 | Super admin OTP quota exhaustion / code cancellation | Low | Open |
| P1-06 | Email change enumeration and email flooding | Low | Open |
| P1-07 | Append-only log bypass by the app DB role (TRUNCATE, session flag, owner) | Low | Open |
| P1-08 | Append-only trigger breaks `ON DELETE SET NULL` | Low | Open |
| P1-09 | Monthly report recipients: any user accepted, list cannot be emptied | Low | Open |
| P1-10 | Per-IP limit blocks the shared office IP | Info | – |
| P1-11 | `view_logs` admins see super admin events | Info | Owner decision |
| P1-12 | Whole-account lock never triggered by external attacker (Q2) | Info | Owner decision |
| P1-13 | Photos not re-encoded (EXIF/polyglot) | Info | – |
| P1-14 | Sign-up timing difference | Info | – |

## Passed checks
**Phase 0 re-test:** P0-02, P0-03 and P0-04 are fixed (see `phase-0-security.md`).

**Sign-up / approval / identification codes**
- `POST /auth/register` returns the same `202` message for new, existing, pending and case-variant emails. A rejected (soft-deleted) email can sign up again or be created by an admin (partial unique index).
- Mass assignment on register (`role`, `status`, `password`) is ignored: the account is created as `employee/pending` with no password. The rate limit is 5/hour/IP (429 after that, before validation).
- Approval under concurrency: 40 parallel approvals by two super admins → exactly 1 × 200 and 1 code email (the rest got 403/409). Approve vs reject in parallel → one wins, and no code is emailed for a rejected request.
- Identification codes are stored as bcrypt (`$2y$`) and never returned by the API. The first sign-in has `must_change_password=true`, and every route except `/me`, `/me/password` and `/auth/logout` returns `403 password_change_required`. The old code stops working after the change. An expired code is refused (`code_expired`, shown only when the code is correct). A new code from an admin replaces the personal password and revokes every token.

**Super admin sign-in**
- OTPs are hashed, single use (replay → 401), and only the latest code works. Expired codes are refused. Requests are limited to 5/hour per account (and 20/hour per IP), the response is always the same `202`, and case/space email variants are normalised.
- Recovery link: sent automatically when a super admin bucket locks. The 64-character token is stored hashed. Single use (20 parallel confirmations → 1 × 200, 19 × 422), the expired link is refused, and a new link invalidates the old one. `/auth/recovery` sends nothing for an unlocked super admin or for an employee, with the same response.
- Two super admins blocking each other at the same time → exactly one succeeds and the other gets `409 last_super_admin` (3 runs). Nobody can block, delete or change the role of their own account (403).

**Lockout**
- 5/5/5 on a known device: 4 × 401, then 429 "Try again in 10 minutes", then 20 minutes, then 423. The counter resets on success. Durations come from settings.
- Rotating `device_id` from unknown devices shares one bucket, so rotation does not help. The owner's known device still signs in while the unknown bucket is locked.
- Known device bucket + unknown bucket locked → account `locked`, all tokens revoked, staff with `block_users` notified, user emailed. Unlock by a super admin restores access.
- `X-Forwarded-For` spoofing does not bypass the per-IP limit (no trusted proxy configured).

**Tokens / sessions**
- Access is lost immediately (next request → 401) on block, delete, role change, new identification code, account lock, and re-login on the same device (one token per device holds even with 20 parallel logins on one device). A password change keeps the current token and revokes the others. Removing an admin permission takes effect on the next request.
- No `Set-Cookie` on any response.

**Authorization** (`tests/security/authz_matrix.sh`, 68/68 passed)
- Employee and admin without tiers: 403 on every `/admin/*` route (users list/show/create/approve/reject/block/unblock/unlock/code/delete/role/permissions, settings, settings history, permissions catalogue, activity logs).
- Admin with every management tier: allowed on employees. 403 on admins, super admins and self for show/block/unlock/code/delete/role/permissions. Cannot create admins or super admins. Cannot see admins/super admins in the list (`role=super_admin` filter → 0).
- Non-numeric/oversized ids → 404. Notifications of another user → 404.
- Mass assignment on `PATCH /me` (`role`, `status`, `email`, `password`, `must_change_password`, `permissions`, `id`, `photo_path`, `approved_by`) is ignored.

**Settings**: unknown keys, the read-only `timezone`, out-of-range values, wrong types, malformed list items and a past `effective_from` are all rejected with 422. Every settings route needs super admin.

**Activity log**: no route can update or delete entries (404/405). SQL `UPDATE`/`DELETE` are refused by the trigger (bypasses: see P1-07). No password, code or token was found in `activity_logs` contexts or in `laravel.log` outside the log-mailer output (the log mailer writes emails, including codes, to the log by design; never use `MAIL_MAILER=log` online).

**Files**
- PHP disguised as JPEG, SVG, GIF+PHP renamed `.png`, and a path-traversal filename are rejected (422). Oversize uploads are rejected. Stored names are server UUIDs under `photos/<user_id>/`.
- Signed URL: valid for `file_url_ttl_minutes` (10 min). Missing signature, a changed `expires`, a different path with the same signature, `..` traversal and a forged Host header all get 404. Responses use `nosniff`, `Content-Disposition: inline` and `Cache-Control: no-store, private`.

**Email change**: the old link is cancelled by a new request. Two users confirming the same new address in parallel → one 200, one clean 422 (no 500).

**Input / disclosure (production mode)**: SQL-injection strings in email and `q` are handled as data. Arrays/objects in string fields, invalid UTF-8 and truncated JSON → 422 JSON. `/.env`, `/.git/HEAD`, `/composer.json`, `/docs/api/openapi.yaml`, `/storage/logs/laravel.log`, `/vendor/...`, `/phpinfo.php` → 404. No `X-Powered-By`/`Server`. Stored XSS strings are returned JSON-escaped (the frontend must keep escaping them). Mail templates have no unescaped `{!! !!}`. A CORS preflight from a foreign origin is not reflected.

**Load (local php -S, 16 workers, not representative of production latency)**: 200 parallel logins → 0 × 5xx (401/429 only), p50 4.6 s / p95 5.5 s / p99 5.7 s (bcrypt-bound on 4 CPUs). 200 parallel sign-ups → 0 × 5xx, p50 0.38 s / p95 0.54 s / p99 0.62 s.
