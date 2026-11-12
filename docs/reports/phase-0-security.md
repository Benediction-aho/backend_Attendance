# Phase 0 – Security report (backend skeleton)

Date: 2026-11-12 · Branch `claude/upbeat-noether-f7defe` · Tester: backend-security-tester
Environment: local only, `php artisan serve` with (a) `APP_ENV=production APP_DEBUG=false` and (b) `APP_ENV=local APP_DEBUG=true`. Docker files reviewed statically (not built).

## Findings

### P0-01 – Legacy admin password in git history — **High**
- Files: `scripts/fixAdminPassword.js`, `scripts/seedAdmin.js` (added in commit `2e18271`, deleted in `53baf89`, but still in the history).
- Repro: `git log -p --all -- scripts/fixAdminPassword.js | grep NEW_PASSWORD` (value deliberately not copied into this report).
- Observed: a v1 admin password is stored in plaintext and anyone who can read the repository can see it.
- Expected: no credentials anywhere in the history.
- Fix: assume it is compromised. Change it everywhere it is or was used (the v1 production admin and any account that reuses it) and never use it for v2. A history rewrite is not needed (and force-push is forbidden); changing the password is the fix. This needs the owner, so log it in PROGRESS.md under "Blocked / questions".

### P0-02 – `/sanctum/csrf-cookie` exposed: Laravel fingerprint and `XSRF-TOKEN` cookie — **Medium**
- Endpoint: `GET /sanctum/csrf-cookie` (the Sanctum package registers it automatically).
- Repro: `curl -si http://127.0.0.1:8181/sanctum/csrf-cookie` → `204`, `Set-Cookie: XSRF-TOKEN=…` and `Set-Cookie: sid=…`.
- Observed: the well-known Laravel/Sanctum path and the Laravel-specific `XSRF-TOKEN` cookie name reveal the framework. This breaks security.md §3 ("pas de jeton CSRF au nom de Laravel visible"). The request also creates a session on an API that is meant to use tokens.
- Expected: 404 JSON, no cookies.
- Fix: if the API uses only bearer tokens, call `Sanctum::ignoreRoutes()` in `AppServiceProvider::register()` and keep `statefulApi` off. If cookie-based SPA auth is chosen later, use a neutral cookie name and a neutral route path.

### P0-03 – Session cookie is not `Secure` by default — **Low**
- Observed: in production mode the `sid` cookie has no `secure` flag (`SESSION_SECURE_COOKIE` is not set).
- Fix: default `secure` to true in production, or require `SESSION_SECURE_COOKIE=true` in test/prod. This becomes moot if the P0-02 fix removes all cookies.

### P0-04 – Caddy trusts `X-Forwarded-*` from private ranges but Laravel trusts no proxy — **Low**
- Files: `docker/Caddyfile` (`trusted_proxies static private_ranges`), `app/Http/Middleware/TrustProxies.php`.
- Observed (local): with `TRUSTED_PROXIES` empty, Laravel ignores forged `X-Forwarded-For/Host/Proto` (no HSTS added, forged host not used), which is correct. But behind the host's proxy, every client will show the proxy's IP. Future per-IP limits (login lockout) would then lump all users together.
- Fix: document in the deploy notes that `TRUSTED_PROXIES` must be set to the exact proxy range in test/prod (`*` only if the container can be reached through the proxy alone). Re-test in phase 1.

### P0-05 – CORS returns the single allowed origin for every request — **Info**
- Repro: `curl -si -H 'Origin: https://evil.com' …/api/health` → `Access-Control-Allow-Origin: http://localhost:5173`.
- Not exploitable: the browser compares it with the real origin, and credentials are disabled. This is standard Laravel behaviour. In test/prod, set `CORS_ALLOWED_ORIGINS` to the exact PWA origin (the default is localhost).

### P0-06 – Dev compose file hardcodes DB password `attendance` — **Info**
- Fine for local use only. Do not reuse it for test/prod.

### P0-07 – Debug mode shows error details — **Info (expected)**
- With `APP_DEBUG=true`, error bodies include the exception class, the message and the absolute file path. That is correct for local work. The production image forces `APP_DEBUG=false`, and deployment must never override it.

## Summary

| ID | Title | Severity | Status |
|---|---|---|---|
| P0-01 | Legacy admin password in git history | High | Open (owner must change it) |
| P0-02 | `/sanctum/csrf-cookie` + `XSRF-TOKEN` fingerprint | Medium | Open |
| P0-03 | Session cookie not Secure by default | Low | Open |
| P0-04 | Caddy and Laravel disagree on trusted proxies | Low | Open (documentation) |
| P0-05 | CORS origin echo | Info | – |
| P0-06 | Dev compose password | Info | – |
| P0-07 | Debug details in debug mode | Info | – |

## Passed checks (production mode)
- No `X-Powered-By` header and no `Server`/PHP/Laravel version headers.
- Errors return generic JSON with no trace: 404, 405 (with `Allow`), malformed JSON, a 20 MB payload (→ 413), a null-byte path (→ 404). 500/422 checked by reading the error renderer, not over HTTP: it returns a generic message and adds the debug block only when `app.debug === true`.
- These paths all return 404: `/.env`, `/.git/config`, `/composer.json`, `/vendor/autoload.php`, `/storage/*`, the `/storage/` directory listing, `/index.php`, `/up`, `/telescope`, `/horizon`, `/docs`, `/api/docs`, `/openapi.yaml`, `/docs/api/openapi.yaml`. A forged `Host` header gets a normal 404.
- Every response has the security headers: nosniff, `X-Frame-Options: DENY`, Referrer-Policy, Permissions-Policy, COOP, CORP, a strict CSP, and `Cache-Control: no-store` on `/api/*`.
- HSTS is sent only on real HTTPS. A forged `X-Forwarded-Proto` does not trigger it.
- CORS: credentials are off and a disallowed origin is never reflected.
- The session cookie has a neutral name (`sid`), is httponly and samesite=lax.
- Dockerfile: multi-stage build, no dev dependencies, runs as a non-root user (uid 10001), `.env*`/tests/docs are removed from the image, `APP_DEBUG=false`, `display_errors=Off`, the Caddy admin endpoint is off, dotfiles and composer files are blocked, and only `index.php` runs.
- `.env.example` has placeholders only. No `.env` or key file is tracked. The git history contains no APP_KEY, API key or private key; the only finding is P0-01 (the other matches were v1 placeholder values).

## Fixes applied (attendance-backend, 2026-11-12) – to be re-verified by backend-security-tester

| ID | Status | Fix |
|---|---|---|
| P0-01 | Owner action | Listed under "Blocked / questions for the owner" in `PROGRESS.md` (change the legacy password everywhere; no history rewrite). |
| P0-02 | Fixed | Decision: Bearer device tokens only. `config/sanctum.php` `routes => false` (no `/sanctum/csrf-cookie`), `statefulApi` stays off, no session middleware on the API. Test `tests/Feature/NoCookieAndProxyTest.php`. Recorded in `docs/api/openapi.yaml` and `PROGRESS.md`. |
| P0-03 | Fixed | Moot: the API sets no cookie at all. Test asserts no `Set-Cookie` on API responses (success, 404, 405, 401, 422). |
| P0-04 | Fixed (documentation + tests) | `TRUSTED_PROXIES` documented in `docs/DEPLOYMENT.md`. Tests: a forged `X-Forwarded-For` is ignored when `TRUSTED_PROXIES` is empty and honoured only when the request comes from a configured proxy range. |
| – | Done | CI PostgreSQL aligned to 17 (Supabase), docker-compose too. |
