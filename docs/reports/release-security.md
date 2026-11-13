# Pre-release security audit – Assistance Ghana Attendance v2

- Date: 2026-11-13 · Tester: `backend-security-tester` · Branch `claude/upbeat-noether-f7defe`
- Scope A (local, aggressive): Laravel API in production mode (`APP_ENV=production APP_DEBUG=false`, `MAIL_MAILER=log`, dedicated DB `attendance_relsec`), `php artisan serve` on ports 8282 (main), 8283 (PHP limits of `docker/php.ini`), 8284 (`TRUSTED_PROXIES=*` as in `render.yaml`), 8285 (`QUEUE_CONNECTION=database` as online). Several source IPs simulated with `curl --interface 127.0.0.x`.
- Scope B: production build of the frontend (`vite build`, `VITE_API_URL=https://api.example.invalid/api`) and its hosting configuration.
- Scope C (deployed test environment, passive): **not executed** – this sandbox's egress proxy refuses CONNECT to both `attendance-api-1xgl.onrender.com` and `attendance.assistanceghana.com` (403 `connect_rejected`, policy denial). No request reached the test environment. See REL-15 for the checklist to run from an allowed network.
- No real email was sent (log mailer). No application code modified.

Scripts added: `tests/security/release_fixtures.sh` (users of every role/tier through the API), `tests/security/release_matrix.sh` (route × role/tier matrix), `tests/security/release_faceid.php` (Face ID + check-in abuse over HTTP with a software authenticator). Other commands are reproduced inline below.

## Findings

### REL-01 – Super admin e-mail enumeration through response time of `POST /api/auth/super-admin/code` — Medium
- File: `app/Services/Auth/SuperAdminCodeService.php::request()`.
- Steps (server with `QUEUE_CONNECTION=database`, like online): send the same request for a candidate e-mail from successive source IPs (each IP stays under the per-IP limit):
  ```bash
  t(){ curl -s -o /dev/null -w "%{time_total}\n" --interface 127.0.1.$2 -X POST localhost:8285/api/auth/super-admin/code \
       -H 'Accept: application/json' -H 'Content-Type: application/json' -d "{\"email\":\"$1\"}"; }
  for i in $(seq 1 12); do t sa1@relsec.test $i; t nobody$i@relsec.test $((i+100)); t e1@relsec.test $((i+200)); done
  ```
- Observed (seconds): unknown e-mail avg **0.251**, employee e-mail avg **0.249**, super admin: 0.48, 0.26, 0.28, then **0.035 – 0.043** for every further request. Once `otp_max_active_codes` (3) valid codes exist, the transaction returns `false` and the function returns without any bcrypt; the other paths run `Hash::make` (~220 ms). Four requests from four IPs are enough to tell whether an address belongs to a super admin. Same body (202) in every case, so only timing leaks.
- Expected: identical cost on every path (security.md: identical messages and similar timing).
- Fix: on the `! $created` path (and any other early return after the limiter) run the same `Hash::make('timing-equaliser')`; add a test with a counting hasher (1 make on every path), like P1-02 for login.

### REL-02 – Recovery link quota is per account only: anyone can exhaust a locked super admin's recovery — Medium
- File: `SuperAdminCodeService::requestRecovery()` – key `recovery:<account>` (no IP component), `otp_requests_per_hour` (5).
- Steps: `for ip in $(seq 30 49); do for k in 1 2 3 4; do curl -s -o /dev/null -w "%{http_code}\n" --interface 127.0.0.$ip -X POST localhost:8282/api/auth/recovery -H 'Accept: application/json' -H 'Content-Type: application/json' -d '{"email":"sa1@relsec.test"}'; done; done | sort | uniq -c` → 80 × 202, but only **5** "Restore access to your account" mails were produced for the whole hour, whatever the source IP. The quota is consumed even while the super admin is not locked and even by requests that send nothing.
- Impact: the recovery link is the only self-service way out for a super admin whose sign-in is locked (3rd series, or new phone after the unknown-device bucket was locked by an attacker: 15 failed codes from unknown devices). An attacker who keeps sending 5 requests per hour (from any IP) keeps the legitimate super admin from ever receiving a link; with a single super admin, the account is unrecoverable without database access.
- Expected: a stranger cannot use up the owner's quota (same principle already applied to sign-in codes, P1-05).
- Fix: count per (account, IP) like `request()`, plus a global per-account cap on links actually **sent** (not on requests), only incremented when a link is emailed; let a request from a known device of the account bypass/evict; log contention as critical.

### REL-03 – Sign-up flood: every request e-mails every approver, per-IP limit only, attacker text in trusted e-mails — Medium
- Files: `AuthController::register`, `AccountService::register` (StaffAlert "New account request" to every holder of `approve_users` + super admins).
- Steps: 60 sign-ups from 127.0.0.1 → 49 × 202 then 429, **98 e-mails** to the 2 approvers; from another source IP (`--interface 127.0.0.2`) the next sign-up is accepted immediately. With `TRUSTED_PROXIES=*` and no appending proxy (port 8284), `X-Forwarded-For: 203.0.113.N` gives a fresh quota per request (see REL-05).
  Content injection: `first_name` = `[URGENT: verify your account](https://evil.example/login)`, `job_title` = `Your password expires, sign in at https://evil.example` → mail body "… wants to create an account" contains the attacker URL verbatim (HTML escaped, but mail clients auto-link bare URLs), sent from the company's own domain.
- Impact: an attacker with a pool of IPs (or a botnet) can flood the approvers' mailboxes from the official sender (deliverability/reputation of the Resend domain, real requests drowned), fill the `users` table with pending rows (no global cap, no expiry of pending requests) and use the trusted sender for phishing text.
- Expected: bounded number of alert e-mails whatever the number of source IPs.
- Fix: global cap `signup_requests_global_per_hour`; above a small threshold (e.g. 5 pending in 1 h) send one digest notification instead of one e-mail per request; reject URLs/`http`/`www.`/`[`/`](` in names and job title (letters, spaces, `-'.` only for names); purge pending requests older than N days; optionally a proof-of-work/captcha on sign-up.

### REL-04 – Frontend hosting configuration has no security headers (clickjacking, no CSP) — Medium
- Files: `frontend_Attendance/vercel.json` (only `Cache-Control` for `/sw.js`), `index.html` (no CSP meta).
- Observed (config + build): no `Content-Security-Policy`, no `frame-ancestors`/`X-Frame-Options`, no `Referrer-Policy`, no `Permissions-Policy`, no `X-Content-Type-Options` on the PWA. Live headers could not be checked (REL-15).
- Impact: the admin screens (approve users, settings, revoke Face ID, check-in on behalf) can be framed by any site → clickjacking; no defence-in-depth if an XSS ever appears (the bearer token lives in IndexedDB and would be readable).
- Fix (vercel.json `headers` for `/(.*)`): `Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https://<storage-host>; connect-src 'self' https://<api-host>; worker-src 'self'; manifest-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'; object-src 'none'`, `X-Frame-Options: DENY`, `Referrer-Policy: no-referrer` (recovery / e-mail-change links carry `?token=`), `Permissions-Policy: camera=(), microphone=(), geolocation=(self), payment=()`, `X-Content-Type-Options: nosniff`, `Strict-Transport-Security: max-age=31536000; includeSubDomains`. Same on whatever host serves the final domain.

### REL-05 – `TRUSTED_PROXIES="*"` in `render.yaml` trusts every forwarded header — Medium (must be verified on Render)
- Files: `render.yaml` (`TRUSTED_PROXIES: "*"`), `app/Http/Middleware/TrustProxies.php` (default header set: For, Host, Port, Proto, Prefix).
- Steps (port 8284, `TRUSTED_PROXIES=*`, client connects directly):
  - `X-Forwarded-For: 203.0.113.1/2/3` → three sign-ups accepted while 127.0.0.1 is already at 429: every per-IP limit (login IP limit, sign-up, OTP, recovery) is keyed on a client-chosen value when nothing in front appends/overwrites the header.
  - `X-Forwarded-Host: evil.example` + `X-Forwarded-Proto: https` → `GET /admin/reports/weekly/submissions/2` returns `https://evil.example/api/files/...?...&signature=…` (signed URLs and any `url()` built from the request use the attacker host).
- On Render the result depends on how its edge builds `X-Forwarded-For` (append vs. overwrite, Cloudflare in front or not). With '*' Laravel trusts only `REMOTE_ADDR`, so it is safe **only if** the edge appends the real client IP as the last element. Not verifiable from this sandbox.
- Fix: verify on the test service (two requests with a forged `X-Forwarded-For`, compare the IP written in `activity_logs`); restrict the trusted headers to `X-Forwarded-For | X-Forwarded-Proto` (`$headers` in `TrustProxies`, drop Host/Port/Prefix); force the root URL (`URL::forceRootUrl(config('app.url'))`, `URL::forceScheme('https')`) so links never depend on request headers; document the result in `DEPLOYMENT.md`.

### REL-06 – Multi-megabyte JSON bodies parsed by global middleware before any throttle — Low
- File: `RejectControlCharacters` (global, runs before `throttle:api`), `docker/php.ini` `post_max_size = 22M`, `memory_limit = 256M`.
- Steps (port 8283, php.ini limits of the image): 18 MB JSON object with 1.2 M keys to `POST /api/auth/login` (unauthenticated) → 401 after **3.2 s** of CPU in one worker; a 17.5 MB array → 413 after 3.4 s. The body is fully decoded and walked recursively before the per-IP limiter runs, so throttling gives no protection.
- Impact: a few parallel uploads saturate the small number of PHP workers / memory of a starter instance (availability). Bandwidth-bound, hence Low.
- Fix: reject `Content-Length` > 64 KB for JSON requests (except the upload routes, which are multipart) in a first global middleware or at the web server (`request_body` max in Caddy per path); run the limiter before input inspection.

### REL-07 – `render.yaml` does not set `PRIVATE_FILES_DISK` (files on ephemeral, per-container disk) — Low
- `config/filesystems.php` defaults to `local`; `DEPLOYMENT.md` §2 says online must be `s3` (Supabase Storage). The blueprint defines web, worker and scheduler as separate containers: with `local`, weekly PDFs/photos uploaded on the web container are not visible to the worker (forwarding e-mails) or the scheduler (monthly report), and are lost on each deploy. Integrity/availability of reports, plus local signed URLs depend on REL-05.
- Fix: add `PRIVATE_FILES_DISK=s3` and the `AWS_*` keys (`sync: false`) to the blueprint, or assert at boot in production that the private disk is not `local`.

### REL-08 – Permission `promote_to_super_admin` is never enforced — Info
- `app/Enums/Permission.php` only; `PUT /admin/users/6/role {"role":"super_admin"}` by a super admin on an admin **without** that tier → 200. Matches open question Q3 but the tier is currently decorative. Either enforce it (target must hold it) or remove it from the catalogue.

### REL-09 – Scheduler tick endpoint discloses its existence — Info
- Wrong/missing token → 404 (good), but `GET /api/ops/scheduler-tick` → **405** and the 11th POST in a minute → **429**, both distinguishable from an unknown route. Fix: register the route only when `SCHEDULER_TICK_TOKEN` is set, and use `match(['get','post'])` returning 404 for GET. Positive checks: valid token → 204, 3 ticks in a row → no duplicate run, token in query string ignored, wrong tokens logged.

### REL-10 – Validation before authorization on `POST /api/admin/users` — Info
- An employee posting an empty body gets 422 with the list of required fields instead of 403 (with a valid body: 403). Minor schema disclosure. Fix: `authorize()` first.

### REL-11 – Dependencies — Info
- `composer audit`: no advisory. `npm audit` (frontend): 14 (2 low, 12 moderate), **all dev-only** (eslint/typescript-eslint/minimatch, workbox-build/glob, deepmerge); `npm audit --omit=dev`: 0. Update when convenient.

### REL-12 – Tailwind version in the CSS bundle — Info
- `dist/assets/*.css` starts with `/*! tailwindcss v4.3.5 | MIT License | https://tailwindcss.com */`. Harmless; strip with the CSS minifier's comment option if "no library mentions" is a requirement.

### REL-13 – `checkin_opens_at` may be later than `late_after` — Info
- `PUT /admin/settings {"checkin_opens_at":"12:00","late_after":"08:10"}` → 200: every check-in would be late. Add a cross-field rule (`checkin_opens_at < late_after`).

### REL-14 – Test environment headers/files not verified — Info (blocked)
- Egress proxy of this sandbox: `CONNECT attendance-api-1xgl.onrender.com:443` and `attendance.assistanceghana.com:443` → 403 policy denial. To run from an allowed network (≤ 20 requests, no brute force):
  `curl -sI https://attendance-api-1xgl.onrender.com/api/health`; `curl -s -o /dev/null -w '%{http_code}\n' $API/{.env,.git/config,composer.json,storage/logs/laravel.log,telescope,horizon,api/documentation,phpinfo.php,sanctum/csrf-cookie}` (all 404 JSON expected); `curl -s -X POST $API/api/ops/scheduler-tick` (404); `curl -s -X POST $API/api/auth/login -H 'Content-Type: application/json' -d '{"email":'` (422 JSON, no trace); `curl -sI -X OPTIONS $API/api/auth/login -H 'Origin: https://evil.example' -H 'Access-Control-Request-Method: POST'` (allow-origin must stay the frontend origin); `curl -sI https://attendance.assistanceghana.com/` (REL-04 headers, HSTS); `curl -s -o /dev/null -w '%{http_code}' https://attendance.assistanceghana.com/assets/<hash>.js.map` (404); `testssl.sh`/SSL Labs for TLS. Also REL-05 verification.

## Summary

| ID | Title | Severity | Status |
|---|---|---|---|
| REL-01 | Super admin enumeration by timing (`/auth/super-admin/code`) | Medium | Fixed – verified in re-test |
| REL-02 | Recovery quota exhaustible by anyone | Medium | Fixed – verified (residual REL-20, Info) |
| REL-03 | Sign-up flood e-mails all approvers; text injection | Medium | Fixed – verified (with REL-16) |
| REL-04 | No security headers on the frontend (clickjacking, no CSP) | Medium | Fixed – verified in config (frontend 823482a); live headers still REL-14; see REL-23 |
| REL-05 | `TRUSTED_PROXIES=*` trusts all forwarded headers (verify on Render) | Medium | Fixed – verified locally (live check still REL-14; see REL-17, REL-19) |
| REL-06 | Large JSON bodies parsed before throttling | Low | Fixed – verified |
| REL-07 | `PRIVATE_FILES_DISK` missing from `render.yaml` | Low | Fixed – verified |
| REL-08 | `promote_to_super_admin` not enforced | Info | Fixed – verified |
| REL-09 | Scheduler endpoint existence (405/429) | Info | Fixed – verified (with REL-18) |
| REL-10 | Validation before authorization on user creation | Info | Fixed – verified |
| REL-11 | Dev-only npm advisories | Info | Open (frontend) |
| REL-12 | Tailwind version comment | Info | Fixed – verified (built CSS has no banner) |
| REL-13 | `checkin_opens_at` vs `late_after` consistency | Info | Fixed – verified |
| REL-16 | URL in the sign-up e-mail (quoted local part) reaches approver e-mails | Medium | Fixed – verified |
| REL-17 | Per-IP limits keyed on the full IPv6 address | Low | Fixed – verified |
| REL-18 | Scheduler tick: wrong-token path measurably slower than an unknown route | Info | Fixed for timing – verified; regression REL-22 |
| REL-19 | Several `X-Forwarded-For` header lines: the first one is used | Info | Not reproducible locally (php -S keeps only the first line); verify live (REL-14) |
| REL-20 | Recovery from a new device can still be starved (by design) | Info | Accepted risk – documented (DEPLOYMENT.md §3.6) |
| REL-22 | Scheduler wrong-token refusals no longer counted nor logged (`defer` skipped on 404) | Low | Fixed (backend) |
| REL-23 | Frontend CSP: no storage host in `img-src`/`connect-src`, API host hard-coded | Info | Documented (backend, DEPLOYMENT.md §3.7) – CSP change open (frontend) |
| REL-24 | Sign-up e-mail pattern refuses valid addresses with `'` | Info | Fixed (backend) |
| REL-21 | Global sign-up cap can be exhausted from many IPs | Info | Accepted risk – document (new, re-test) |
| REL-14 | Live test environment not reachable from sandbox | Info | Blocked |

Critical: 0 · High: 0 · Medium: 5 · Low: 2 · Info: 7 (initial pass). After re-test: open Medium = REL-04 (frontend) and REL-16 (new); open Low = REL-17. Previously open P0-01 (legacy v1 password in git history, owner action) still applies.

## Fixes applied (attendance-backend, 2026-11-13)

All backend findings fixed with Pest regression tests; full suite green, PHPStan clean. REL-04, REL-11, REL-12 belong to the frontend repository; REL-14 still has to be run from an allowed network.

- **REL-01** – `SuperAdminCodeService::request()`: the "too many valid codes" path now runs the same `Hash::make('timing-equaliser')`; `requestRecovery()` also costs exactly one hash on every path (throttled, unknown, not locked, cap reached, link sent). Tests: counting hasher – exactly 1 make for unknown e-mail, employee, super admin, and super admin after the active-code cap (`SuperAdminLoginTest` "REL-01 …").
- **REL-02** – Recovery requests counted per (account, IP) + per IP (key `recovery:<account>:<sha256(ip)>`); new setting `recovery_links_sent_per_hour` (3) caps links actually e-mailed per account, incremented only when a link is due to be sent; a request carrying the `device_id` of a known device of the super admin bypasses the cap; contention logged as `auth.recovery_contention` (critical). `POST /auth/recovery` accepts optional `device_id`. Tests: 20 IPs × 4 requests on a locked super admin → 3 e-mails + critical log; known device still gets a link, unknown device does not; requests made while not locked consume nothing.
- **REL-03** – Global cap `signup_requests_global_per_hour` (200, counted once the request is well formed); approver e-mails bounded by `signup_alert_emails_per_hour` (5): the next request triggers one summary e-mail ("Many new account requests"), the rest are in-app notifications only; names restricted to letters/spaces/`-'.` and job title to letters/digits/simple punctuation, anything link-like (`://`, `www.`, a dot glued to a word, `@`, brackets, `<>`) refused with 422; text is also neutralised (`App\Support\SignupText::neutralize`) before it is put in an e-mail; pending requests older than `pending_signup_retention_days` (30) are removed by the hourly purge (same as a rejection: status deleted + soft delete, e-mail free again, `user.signup_expired` logged). Tests: global cap across IPs + reset after an hour; 6 sign-ups with limit 2 → 2 e-mails, 1 summary, 4 in-app only; 9 injection payloads refused; real names (accents, apostrophes, `J. `) accepted; purge (idempotent, active accounts untouched). Captcha not added (question Q10 in PROGRESS.md).
- **REL-05** – Root cause found while fixing: stock Laravel `TrustProxies` turns `*` into "trust 0.0.0.0/0", so the **left-most** (client-chosen) `X-Forwarded-For` entry became the client IP even behind an appending proxy. `App\Http\Middleware\TrustProxies` now maps `*` to `REMOTE_ADDR` (the immediate peer only): the client IP is the entry Render's proxy appends. Trusted headers restricted to `X-Forwarded-For | X-Forwarded-Proto`; `URL::forceRootUrl(APP_URL)` + scheme forced from APP_URL. `render.yaml` comment and `docs/DEPLOYMENT.md` §3.2 + new §3.4 (verification procedure on Render, what to do if a second hop such as a CDN exists). Tests: `X-Forwarded-For: 203.0.113.1, 198.51.100.44` from proxy 10.1.2.3 → client IP 198.51.100.44; forged `X-Forwarded-Host/Proto/Port/Prefix` never appear in a signed URL. Still to verify live (REL-14).
- **REL-06** – New first global middleware `LimitRequestBody` (after `SecurityHeaders`, before CORS/proxies/control-character scan/throttle): non-multipart body with `Content-Length` (or measured raw size when absent) above `MAX_REQUEST_BODY_KB` (config `app.max_body_kb`, default 128 KB – largest legitimate field is 16 384 characters) → 413 `payload_too_large` without decoding. Multipart uploads keep their per-route limits. Tests: 70 KB JSON to `/auth/login` → 413 with and without Content-Length, no login attempt recorded; normal login still 401; multipart photo upload with a 5 MB Content-Length is not refused by the middleware.
- **REL-07** – `render.yaml`: `PRIVATE_FILES_DISK=s3`, `AWS_DEFAULT_REGION`, `AWS_ENDPOINT`, `AWS_USE_PATH_STYLE_ENDPOINT` in the shared group, `AWS_ACCESS_KEY_ID`/`AWS_SECRET_ACCESS_KEY`/`AWS_BUCKET` as `sync: false` on web, worker and scheduler. `DEPLOYMENT.md` note: the free-plan setup (single API container, §3.3) technically works with `local` but loses files on every deploy; use `s3` as soon as the bucket exists. No boot assertion (it would break the single-container free plan and local development).
- **REL-08** – Enforced: promotion to super admin requires the target to be an admin holding `promote_to_super_admin` → otherwise 422 `not_eligible_for_super_admin`. Configurable by a super admin only (`super_admin_promotion_requires_tier`, default true). Tests: employee and tier-less admin refused (role unchanged), eligible admin promoted, setting off → allowed.
- **REL-09** – Route is `Route::any` without the general `throttle:api`; the controller answers 404 for any method other than POST, when disabled, for a wrong token and once its own per-IP limit (10/min) is reached – no 405, no 429. Tests: GET/PUT/PATCH/DELETE → same JSON as an unknown route; 15 bad calls then a valid token → 404, scheduler not run; 40 calls with a low `api_requests_per_minute` → always 404.
- **REL-10** – `POST /admin/users` authorizes (role read from input, default employee) before validating. Tests: employee with empty body → 403 without `errors`; `create_users` admin asking for an admin → 403; valid caller with empty body → 422.
- **REL-13** – `PUT /admin/settings` refuses `checkin_opens_at >= late_after` (each value taken from the request or from the value effective at the change date) with 422 on `values.checkin_opens_at`. Tests: opens after late, equal, `late_after` moved before opening → 422 and nothing stored; consistent changes accepted.

## Re-test (backend-security-tester, 2026-11-13, commit ab97724)

Setup: fresh DB `attendance_relsec2`, production mode, `php -S` on 8282 (sync queue, `SCHEDULER_TICK_TOKEN` set), 8283 (`-c docker/php.ini`), 8284 (`TRUSTED_PROXIES=*`), 8285 (`QUEUE_CONNECTION=database`); fixtures through the API (`release_fixtures.sh` + identification-code change). New scripts: `tests/security/release_retest_timing.sh`, `tests/security/release_retest_signup.py`.

| ID | Result | Evidence |
|---|---|---|
| REL-01 | **Fixed** | `P=8285 N=12 bash tests/security/release_retest_timing.sh sa1@… nobody@… e1@… sa1@…` (fresh IP per request): median 0.270 / 0.262 / 0.256 / 0.258 s; super admin past the active-code cap (2nd series) indistinguishable. Recovery (`PATH_=/api/auth/recovery`): SA not locked 0.260, unknown 0.259, employee 0.250; SA locked (links sent, then cap reached): 0.266 vs unknown 0.251 / employee 0.256. |
| REL-02 | **Fixed** | SA locked by 5 wrong codes from `dev-attacker-…`; 30 recovery requests from 30 IPs → 3 links sent, 7 × `auth.recovery_contention` (critical). Then: known `device_id` → link sent despite the cap; unknown device → nothing; leading space/upper-case variants behave as expected (trimmed / unknown). Known device from one IP: limited by the per-(account, IP) quota (5). Residual REL-20. |
| REL-03 | **Partly fixed** | Global cap, approver e-mail budget (5 then one summary, rest in-app) and purge OK. 26 payloads (`release_retest_signup.py`): full-width/ideographic/one-dot-leader/small full stops, zero-width, NBSP, RTL override, newline, Arabic full stop, full-width URL → 422. Accepted but harmless after neutralisation: `evil. example`, combining dot below, Cyrillic homoglyph names, `evil/com/login`, `evil,com`, phone numbers and plain phishing wording (no link). **Bypass: the `email` field** → REL-16. |
| REL-05 | **Fixed (locally)** | Port 8284, IP recorded in `activity_logs`: `XFF: 203.0.113.1, 198.51.100.44` → 198.51.100.44 (right-most); garbage → peer; `Forwarded`, `X-Real-IP` ignored; IPv6 / `[v6]:port` / `v4:port` parsed. `X-Forwarded-Host/Port/Prefix`, forged `Host` → signed photo URL always built on `APP_URL`. Signed URLs only validate when the request host equals `APP_URL` (expected; APP_URL must be the public API origin). See REL-17/REL-19. |
| REL-06 | **Fixed** | Port 8283 (`docker/php.ini`), 10 MB JSON with 600 k keys to `/auth/login`: with Content-Length → 413; `Transfer-Encoding: chunked` → 413; `Content-Encoding: gzip` (2.9 MB) → 413 (never inflated); form-urlencoded → 413; `Content-Type: multipart/form-data; boundary=x/json` with a JSON body → PHP exposes no body, 422 (no JSON parsing); real multipart with a 15 MB text field → 422 in the same time as a 413 (`max_input_vars`=1000 bounds field count). 140 KB `PUT /admin/settings` as super admin → 413. |
| REL-07 | **Fixed** | `render.yaml`: `PRIVATE_FILES_DISK`, `AWS_*` present (`sync: false` for secrets) on web, worker, scheduler. |
| REL-08 | **Fixed** | Tier-less admin and employee → 422 `not_eligible_for_super_admin`; tier holder promoting himself → 403; settings toggle by `manage_settings_security` → 403; eligible admin promoted by super admin → 200 (then demoted back). |
| REL-09 | **Fixed (status codes)** | POST/GET/HEAD without/with wrong token, 13 calls in a minute → always 404 with the same body and headers as an unknown route; valid token after the per-IP limit from the same IP → 404, from another IP → 204. Timing residual → REL-18. (php built-in server answers `HTTP/1.1` for the route and `HTTP/1.0` for unknown routes; Caddy/FrankenPHP rewrite the status line, so not visible online.) |
| REL-10 | **Fixed** | Employee `{}`, `{"role":["x"]}`, `{"role":null}` → 403 without `errors`; `create_users` asking `super_admin` → 403; invalid role values from a valid caller → 422. |
| REL-13 | **Fixed** | `opens 12:00 / late 08:10`, opens alone later than current late, late alone earlier than current opens, with future `effective_from` → 422; bad format → 422. |

Regression: route × role/tier matrix (35 routes × 25 callers) identical to the first pass (only the routes of each tier open; self-view of `GET /admin/users/{self}` allowed by policy); `release_faceid.php` → `fails=0` (perimeter, accuracy, NaN/1e400/array, assertion abuse, cross-user intent, replay idempotent, 10 parallel confirms → 1 × 201 + 9 × 200, one attendance); login lockout 4 × 401 then 429.

### REL-16 – URL injected into approver e-mails through the sign-up `email` field — Medium (bypass of REL-03)
- File: `AuthController::register` (`email:rfc`), `AccountService::alertApprovers()` (name and job title are neutralised, `$user->email` is not).
- Steps (fresh alert budget):
  ```bash
  curl -s --interface 127.41.0.2 -X POST localhost:8282/api/auth/register -H 'Accept: application/json' -H 'Content-Type: application/json' \
    -d '{"email":"\"https://evil.example/login?x=2\"@relsec.test","first_name":"Ama","last_name":"Mensah","employee_type":"employee","job_title":"Accountant"}'
  ```
- Observed: 202; approver e-mail (text and HTML parts) and in-app notification: `Ama Mensah ("https://evil.example/login?x=2"@relsec.test, Accountant) wants to create an account.` A quoted local part is valid RFC 5322, so any URL (or `"Your account is locked visit …"`) is carried verbatim, sent from the company domain; mail clients auto-link it. Bounded by `signup_alert_emails_per_hour` (5) but each one is a convincing phishing mail to privileged users.
- Expected: no attacker-controlled link in staff e-mails (REL-03 fix intent).
- Fix: validate sign-up e-mail as a plain address – `email:rfc,strict` is not enough; add `regex:/^[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}$/` (no quotes, no spaces) or `email:filter` (PHP `FILTER_VALIDATE_EMAIL` without unicode refuses quoted parts with `/`? – check) and keep `email:rfc`; and render the e-mail address in the alert through a neutralising helper too (or omit it from the e-mail and show it only in the app). Apply the same rule to `POST /admin/users` and e-mail change.

### REL-17 – Per-IP limits keyed on the full IPv6 address — Low
- Files: every limiter keyed with `$request->ip()` (login IP limit, sign-up per IP, OTP/recovery per IP, scheduler tick).
- Steps: port 8284, `X-Forwarded-For: 2001:db8::1` → recorded IP `2001:db8::1`; each distinct /128 is a fresh bucket. A client with one ordinary IPv6 /64 (or /56, /48 from many providers) has 2^64+ addresses, so per-IP quotas give no protection if the edge accepts IPv6 clients. Account/device lockouts and the new global caps still hold, hence Low.
- Fix: normalise IPv6 to its /64 prefix (IPv4 unchanged, IPv4-mapped `::ffff:a.b.c.d` → IPv4) in one helper used for every limiter key.

### REL-18 – Scheduler tick: wrong-token path slower than an unknown route — Info (residual of REL-09)
- Steps: `bash` 15 POSTs each from a fresh IP with a wrong `X-Scheduler-Token`: `/api/ops/scheduler-tick` median **26–29 ms**, `/api/ops/nonexistent` median **8–9 ms** (the route writes an `ops.scheduler_tick_refused` log row). The endpoint's existence remains detectable by timing; the 32+ character token keeps it safe.
- Fix (optional): do the refusal log asynchronously / only once per IP per minute, or accept and document.

### REL-19 – Several `X-Forwarded-For` header lines: the first one is used — Info (verify live)
- Steps: port 8284, `-H 'X-Forwarded-For: 203.0.113.2' -H 'X-Forwarded-For: 198.51.100.7'` → recorded IP **203.0.113.2** (client-chosen). Safe only if the hosting edge appends to the existing header value (usual) rather than adding a second header line, and if FrankenPHP joins duplicate lines. Add to the REL-14 live checklist: send two forged header lines and compare the IP in `activity_logs`.

### REL-20 – Recovery from a new device can still be starved — Info (accepted risk)
- With the fix, anyone can use up `recovery_links_sent_per_hour` (3) per hour from any IPs; only a request carrying the `device_id` of a known device of the super admin bypasses it. A super admin who lost every known device (new phone) and is locked out cannot get a link while the attacker keeps going (critical log is written). Document the out-of-band procedure (second super admin or `attendance:` command on the server) in `DEPLOYMENT.md`; recommend two super admins.

### REL-21 – Global sign-up cap exhaustible — Info (accepted risk)
- `signup_requests_global_per_hour` (200) is shared by everyone: 200 well-formed requests from many IPs block legitimate sign-ups for up to an hour (429). Acceptable trade-off for the flood protection; mention in the admin guide (setting can be raised, pending requests visible in the app).

## Fixes applied after re-test (attendance-backend, 2026-11-13)

- **REL-16** – One plain-address rule `SignupText::EMAIL_PATTERN` (no quotes, spaces, brackets, IP literals; dotted domain with a TLD) added to `email:rfc` on sign-up, `POST /admin/users` and `POST /me/email` (and `attendance:create-super-admin` unchanged: owner-run). The address is also neutralised in the approver alert (e-mail and in-app text). Tests: quoted URL, quoted text, IP literal, no TLD → 422 on sign-up and user creation; quoted address → 422 on e-mail change; alert body contains no `@` nor `example.com`.
- **REL-17** – `App\Support\ClientIp::bucket()`: IPv4 unchanged, IPv4-mapped IPv6 → IPv4, other IPv6 → /64 prefix. Used for every per-IP key (login IP limit, sign-up, OTP and recovery per IP and per account+IP, scheduler tick, API/attendance/dashboard/client-error/export limiters for guests). Logged IPs stay complete. Tests: helper cases; three sign-ups from one /64 with a limit of 2 → 202, 202, 429.
- **REL-18** – Wrong-token path does no database work before answering: the limiter hit and the `ops.scheduler_tick_refused` log are deferred until after the response (`defer()`); the limit is checked only for a correct token (still 404 for a minute after 10 wrong tokens from the address). Tests: query log empty for a wrong-token request; refusal logged after the response; REL-09 limit test still green.
- **REL-19** – `TrustProxies` joins several `X-Forwarded-For` lines in order before trusting them, so the right-most entry (appended by the trusted proxy) is used. Test: two lines `203.0.113.2` / `198.51.100.7` from the proxy → `198.51.100.7`. Live check added to `DEPLOYMENT.md` §3.4.
- **REL-20** – Out-of-band recovery documented in `DEPLOYMENT.md` §3.6 (second super admin unlocks; otherwise server-side procedure), recommendation of two super admins. Accepted risk.

## Final re-test (backend 04ce7ce, frontend 823482a, 2026-11-13)

- **REL-16 Fixed.** 21 e-mail variants: quoted local part with URL, RFC comments `a(https://…)@`, `a@(…)`, spaces, IP literal, trailing dot, `..`, leading dot, Unicode local part, Unicode domain, list `a@x,b@y` → 422. `a+tag@`, `first.last@sub.`, `A_B-c%d@`, punycode domain → 202 (real addresses still work). Approver e-mails now show `user at relsec. test` (neutralised, never a link). `https.evil.example.login@relsec.test` is accepted but rendered `https. evil. example. login at relsec. test` (no link).
- **REL-17 Fixed.** Port 8284, `signup:` buckets: `2001:db8:1:2::1`, `…:ffff:ffff:ffff:ffff`, uncompressed upper-case form, `[…::43]:443` → one bucket `2001:db8:1:2::/64` (4 hits); `::ffff:203.0.113.5`, `::ffff:cb00:7105` and `203.0.113.5` → one bucket `203.0.113.5`; another /64 → separate bucket; `…%eth0` → invalid, peer address used. Note: NAT64 (`64:ff9b::/96`) clients all share one /64 bucket – acceptable.
- **REL-18 Fixed for timing**: wrong token median 7.5–9.3 ms vs unknown route 8.4–8.5 ms. **But see REL-22.**
- **REL-19**: with `php -S`, two `X-Forwarded-For` lines reach PHP as the first line only (the SAPI drops the rest), so the joining code cannot be exercised locally (`203.0.113.2` + `198.51.100.7` → 203.0.113.2). FrankenPHP/Go keeps all lines; the Pest test covers the joining. Keep in the REL-14 live checklist.
- **REL-04 Fixed (config)**: `vercel.json` sets CSP (`frame-ancestors 'none'`, `object-src 'none'`, `script-src 'self'`), `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy: strict-origin-when-cross-origin` (only the origin leaves cross-site, so `?token=` is not leaked), Permissions-Policy (geolocation self, WebAuthn self), HSTS. Live check still REL-14.
- **REL-12 Fixed**: `vite build` into the scratchpad (repository untouched): no `tailwindcss v…` banner in any asset, no source map.
- Regression: release matrix identical to the previous pass (only differences: tokens revoked by my own role change / code regeneration → 401, expected); `release_faceid.php` with two new employees → `fails=0`, 10 parallel confirms → 1 × 201 + 9 × 200.

### REL-22 – Scheduler wrong-token refusals are no longer counted nor logged — Low (regression of the REL-18 fix)
- File: `SchedulerTickController` – the refusal hit + `ops.scheduler_tick_refused` log run in `defer(...)`; Laravel's `InvokeDeferredCallbacks` only runs deferred callbacks when the status is `< 400` unless `->always()` is used. The answer is 404, so they never run.
- Steps: 12 POSTs with a wrong token from 127.23.0.1, then the valid token from the same IP → **204** (expected 404 for a minute); `select count(*) from activity_logs where event='ops.scheduler_tick_refused'` → **0**.
- Impact: token brute force is neither limited nor visible (a 32+ character token keeps it impractical; monitoring is lost).
- Fix: `defer(fn () => …)->always()` (or `app()->terminating(...)`); add a test that counts the log row and the 404 after 10 wrong tokens.

### REL-23 – Frontend CSP: storage host missing, API host hard-coded — Info
- `img-src`/`connect-src` allow only `'self'` and `https://attendance-api-1xgl.onrender.com`. Once `PRIVATE_FILES_DISK=s3` (REL-07), photos and PDFs are served by pre-signed Supabase Storage URLs on another host → blocked by CSP. The final API domain will also need to be added. Fix: add the storage origin (and the final API origin) when known; keep a note in `DEPLOYMENT.md`.

### REL-24 – Sign-up e-mail pattern refuses valid addresses with an apostrophe — Info
- `o'brien@relsec.test` → 422. Legitimate but rare; add `'` to the local-part class if needed (it is not a link character).

## Fixes applied after final re-test (attendance-backend, 2026-11-13)

- **REL-22** – `defer(...)->always()` in `SchedulerTickController`: counting and the `ops.scheduler_tick_refused` log now run after the 404 too. Test through the HTTP kernel and middleware: 10 wrong tokens → 10 log rows, then the right token from the same IP → 404 and the scheduler not run; another IP → 204. The REL-18 test now calls the controller directly: 404 with no query before the answer, one deferred callback queued.
- **REL-23** – Checked: with `PRIVATE_FILES_DISK=s3` the browser receives **direct pre-signed Supabase Storage URLs** (not proxied); with `local`, API-signed URLs on `APP_URL`. Documented in `DEPLOYMENT.md` §3.7: the frontend CSP must allow the storage origin (`img-src`, `connect-src`) and the final API origin. The frontend change is left to attendance-frontend.
- **REL-24** – Apostrophe allowed in the local part (`o'brien@example.com` → 202); quoted local parts still refused (`"o'brien"@example.com` → 422); the neutralised alert contains no `@`, domain or `://`. Tests added.

## Tests passed

Authentication / lockout
- 5 wrong codes on one device → 4 × 401 then 429 `login_blocked` 600 s; unknown e-mail gets exactly the same sequence (no enumeration by lockout); identical messages.
- Password change: the other device's token → 401 immediately, the current one kept; logout revokes the token; block → `account_blocked` 401 at once; admin code regeneration revokes the user's tokens; deletion revokes.
- Super admin codes: 140 requests from 20 IPs → only 15 codes e-mailed; without a known device the active-code cap refuses silently (202). Recovery link: single use (replay 422), every earlier link invalid after use, 64-char token, link built from `FRONTEND_URL` (a forged `Host`/`X-Forwarded-Host` never changes e-mail links). E-mail change: single use, old address notified, other sessions kept.
- No secret (password, identification code, OTP, recovery/e-mail tokens) found in `activity_logs` (404 rows) nor in the application log outside the log-mailer bodies.

Authorization (matrix in `tests/security/release_matrix.sh`, 35 routes × employee, admin without tier, 22 single-tier admins, super admin)
- Every admin route 403 for employees and tier-less admins; each tier opens only its routes (`view_logs` → logs only, export super admin only, the three settings tiers → settings read/write of their own section only: cross-section writes 403, `timezone` and `logs_admins_see_super_admin_events` super admin only); security settings bounded (otp ≥ 6, password ≥ 8, TTLs, limits).
- Tiered admins cannot act on admins/super admins/self (block, delete, code, check-in on behalf, leaves, schedules); `create_users` cannot create admins; permissions sent for an employee are dropped.
- Last super admin: demote/block/delete/permissions on the only one → 403; two super admins demoting or deleting each other in parallel (5 rounds) → always ≥ 1 active super admin.
- IDOR: other users' tasks/clients (PATCH/DELETE) → 404, other users' notifications → 404 and unchanged, `user_id` in queries/bodies ignored, other users' submissions/credentials → 403.
- Mass assignment: `role`, `status`, `permissions`, `email`, `must_change_password` in `PATCH /me` ignored; `check_in_at`, `is_late`, `work_date`, `user_id` in intents and on-behalf check-ins ignored (server time used); task `user_id`/`work_date`/`completed_at` ignored.

Business rules / Face ID
- Out of perimeter (410 km) 422; accuracy 101 m / 5000 m 422; negative, `NaN`, `1e400`, arrays 422; check-in gate before check-in (`checkin_required`); one check-in per day (409 `already_checked_in`); `checkin_opens_at` bounds validated.
- Face ID: no assertion, other user's credential, wrong origin, no UV → 422 and the intent is burnt; intent confirmed with another user's token → 422; replay of a valid assertion → idempotent 200 `created:false`, no second record; 10 parallel confirms → 1 × 201 + 9 × 200, one attendance; revoked credential → `faceid_not_registered`; new device → `faceid_pending_approval`; `approve_faceid_devices` cannot revoke an approved credential (super admin only).
- Races: 20 parallel creations with `tasks_max_per_day`/`clients_max_per_day` = 5 → exactly 5 each.
- Scheduled jobs run twice in parallel: no error, no duplicate.

Files / input
- Weekly PDF: HTML polyglot, `/J#61vaScript`, `/Launch`, ObjStm-compressed catalog + JavaScript, `/URI` → 422 `pdf_active_content`; MZ header and PHP → `invalid_pdf`; `/Encrypt` → `pdf_encrypted`; 25 MB → 413; client name `../../../../etc/x.pdf.php` stored only as display name, file stored as `weekly-reports/<user>/<week>/<uuid>.pdf`.
- Signed file URLs: valid 200 (attachment, sandbox CSP), path swap / bumped `expires` / missing signature / `..%2f` → 404.
- Photos: SVG with script, PNG header + PHP, 20000 × 20000 PNG bomb → 422.
- SQL-like input in login → 401; invalid JSON / type confusion (`{"$ne":1}`, arrays) → 422; 100 000-level nested JSON → 422; NUL/control characters → 422 (P5-01).
- XSS payloads stored raw and returned as JSON (frontend renders as text, no `dangerouslySetInnerHTML` in `src/`); e-mail HTML escapes attacker text.

Information disclosure (local, production mode)
- `/.env`, `/.git/config`, `/composer.json`, `/storage/logs/laravel.log`, `/telescope`, `/horizon`, `/api/documentation`, `/phpinfo.php`, `/vendor/autoload.php`, `/sanctum/csrf-cookie`, path traversal → JSON 404; 405 and malformed input without stack trace; no `X-Powered-By`/`Server`; strict API headers (CSP `default-src 'none'`, XFO DENY, nosniff, `no-store`); no cookie; CORS fixed to the frontend origin (foreign `Origin` never echoed, no credentials).

Frontend bundle
- No source maps or `sourceMappingURL`; no `console.*` left; no secret/API key/JWT/private key; only the configured API URL; service worker precaches static assets only (`runtimeCaching: []`, `/api/` denied from navigation fallback); token in IndexedDB (not localStorage/cookies/URLs); URL tokens removed from the address bar on load; in-app return path validated (no open redirect); React's own `innerHTML` only.
