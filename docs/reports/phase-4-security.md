# Phase 4 – Backend security report (Face ID passkeys, Web Push, UX2 fixes)

Tester: backend-security-tester – 2026-11-12 – branch `claude/upbeat-noether-f7defe` (after `523cfad`).
Environment: local only. `php artisan serve --port=8181` with `APP_ENV=production APP_DEBUG=false QUEUE_CONNECTION=sync MAIL_MAILER=log WEBAUTHN_RP_ID=attendance.test WEBAUTHN_ORIGINS=https://attendance.test`, **no VAPID keys** (no push ever leaves the machine), PostgreSQL 17 local. No real email or push was sent.

Scripts (all under `tests/security/`):
- `phase4_faceid_push.php` – WebAuthn registration/assertion abuse, review authorization per tier, check-in gate, auto-approval race, Web Push SSRF / limits / IDOR (software authenticator from `tests/Support/webauthn.php`). **87 checks, 0 FAIL** (re-runnable: it resets its own users).
- `phase4_ux2.sh` – UX2-01/02/07/08 from a security angle.
- `phase4_parallel_faceid.php` – Face ID version of `tests/load/parallel_checkin.sh`.

Prerequisites: `php artisan attendance:load-fixtures --count=230`, `php tests/security/phase3_fixtures.php > $P3`, `php artisan cache:clear`, then `P3=$P3 php tests/security/phase4_faceid_push.php`, `bash tests/security/phase4_ux2.sh`, `N=200 php tests/security/phase4_parallel_faceid.php`. The Face ID script temporarily raises `checkin_attempts_per_minute` to 600 and restores 20 at the end (the limit itself is checked last).

**Result: no Critical, High or Medium finding.** 3 Low, 5 Info.

## Findings

### P4-01 – Re-subscribing a known push endpoint moves it to the caller – Low
- Endpoint: `POST /push/subscriptions` (`PushSubscriptionController::store`, upsert on `endpoint_hash`).
- Steps: employee A subscribes endpoint `E`; employee B posts the same `E` with his own `keys` → the row now belongs to B (`user_id` = B) and A silently stops receiving pushes. Script line "re-subscribing a known endpoint moves it to the caller: YES".
- Impact: limited – the endpoint is a secret capability URL known only to A's browser/push service, and B's pushes are encrypted with B's keys so A's browser cannot decrypt/show them. Realistic only on a shared browser (expected behaviour there) or after the endpoint leaks (logs, support screenshots). Effect is a silent DoS of A's notifications.
- Expected: an endpoint already owned by another user is only re-bound when the request comes from the same device session (or when the old owner's token on that device is gone); otherwise refuse (`409`) or keep both owners separate.
- Fix: in the upsert, add `WHERE push_subscriptions.user_id = excluded.user_id OR push_subscriptions.device_id IS NOT DISTINCT FROM excluded.device_id`, or look the row up first and refuse when `user_id` differs and the device differs.

### P4-02 – A new Face ID request silently supersedes the pending one; the account owner is never told – Low
- Endpoint: `POST /me/faceid/register` (`WebAuthnService::register`).
- Steps: user with an approved credential registers on device B (pending `#9`); a third session of the same account (device C, e.g. an attacker who knows the password) registers → `#9` becomes `revoked / superseded`, only C's request is in the approvers' queue. No email/in-app alert is sent to the account owner on any Face ID registration (only approvers are notified).
- Impact: with stolen credentials an attacker can put his own phone in the approval queue (replacing the owner's request) and rely on an approver clicking "approve"; after approval he can check in remotely as the victim (the main purpose of Face ID). Approval is the control, so Low.
- Fix: email + in-app security alert to the account owner for every Face ID registration request ("A new device asked to use Face ID on your account – if this was not you, contact your administrator"); in the admin list show device first seen, last IP/user agent and how many requests were superseded in the last days; optionally refuse superseding a pending request less than N minutes old.

### P4-03 – Web Push client follows HTTP redirects – Low
- File: `app/Services/Push/WebPushTransport.php` (`new WebPush(..., [], 10)` → Guzzle default `allow_redirects` = 5 hops).
- The host allow-list is checked only on the stored endpoint. A 3xx from an allowed push service (or a future allow-list entry such as a self-hosted UnifiedPush/ntfy distributor) would be followed to any host, including internal addresses. No exploitable path today (all allow-listed hosts belong to Google/Apple/Mozilla/Microsoft).
- Fix: pass client options `['allow_redirects' => false, 'connect_timeout' => 5]` (4th constructor argument is the timeout, client options are the `WebPush` constructor's `$clientOptions`); optionally `CURLOPT_PROTOCOLS => CURLPROTO_HTTPS`.

### P4-04 – First-device auto-approval trusts whoever signs in first – Info
- By design (`faceid_first_device_auto_approve`, default true): the first passkey of an account is approved. Whoever obtains the emailed identification code first (shared mailbox, intercepted email) registers his own phone; the real employee then gets `faceid_other_device` and must ask an admin. Parallel first registrations from two devices give exactly one `approved` and one `pending` (partial unique index works – checked).
- Recommendation: same owner alert as P4-02 at first registration; consider setting the default to `false` for the initial roll-out week (owner decision, add to PROGRESS questions).

### P4-05 – Synced passkeys with sign counter 0 cannot reveal clones – Info
- Counter regression is enforced (stored 10 → assertion with 3 or 10 refused 422). Platform passkeys synced through iCloud/Google always send 0; then the counter check is skipped (library `ThrowExceptionIfInvalid`, both 0). Inherent to WebAuthn; the device binding (`credential.device_id === token device`) is the extra control and is enforced.

### P4-06 – Admin accounts cannot register Face ID – Info
- `/me/faceid/*` is behind `EnsureEmployee`, consistent with attendance being employee-only. Admin-owned credentials can therefore only exist if created otherwise; the review endpoints still correctly refuse a tier admin on another admin's credential (seeded directly: 403) and on his own (403).

### P4-07 – Correct code from a new unknown device is refused during a block – Info
- UX2-01 re-check: after 5 failures with whitespace variants (`ABCDE FGHJX`, tabs, zero-width space, lowercase) the device is blocked (429 `login_blocked`), the correct code with spaces is refused on the same device **and on any other unknown device** (shared unknown-device bucket, security.md §1). Expected by design; mentioned because the UX may look surprising to a new employee.

### P4-08 – Per-email sign-up limit lets anyone delay one person's sign-up by an hour – Info
- 3 requests/hour per email (case-insensitive, verified). A third party can exhaust it for a known address; the answer is the same generic 429 for known and unknown emails (no enumeration). Mitigation exists (an admin can create the account directly). Accepted.

## Summary

| ID | Title | Severity | Status |
|---|---|---|---|
| P4-01 | Known push endpoint re-bound to another user | Low | Open |
| P4-02 | Pending Face ID request superseded silently, owner not alerted | Low | Open |
| P4-03 | Web Push transport follows redirects | Low | Open |
| P4-04 | First-device auto-approval trusts first sign-in | Info | Owner decision |
| P4-05 | Counter 0 passkeys cannot reveal clones | Info | Accepted (inherent) |
| P4-06 | Admins cannot register Face ID | Info | By design |
| P4-07 | New unknown device blocked during a block | Info | By design |
| P4-08 | Sign-up per-email limit can be exhausted by a third party | Info | Accepted |

Phase 3 **P3-04 (Face ID not enforced) – Fixed** (see below and `phase-3-security.md`).

## Tests that passed

WebAuthn registration (`POST /me/faceid/register[/options]`):
- Refused 422 with no exception leak: UV flag missing (`0x41`), wrong origin, wrong RP ID hash, `type: webauthn.get`, substituted challenge, malformed CBOR, 5000-level nested CBOR, attestationObject > 16 KB, clientDataJSON not JSON, `credential` as a string, truncated JSON body.
- Challenge of another user → 422; expired challenge → 422; replay of an accepted registration → 422; registration challenge used as a check-in challenge → 422.
- First credential approved; options when already ready → 409; same authenticator (credential id) on another account → 409 (does not bind to the other account).
- Second device → `pending`; intent from it → 403 `faceid_pending_approval`; after approval old device → 403 `faceid_other_device`; after revoke the next registration is `pending` (no auto-approval after a revoke).
- Per-user limit: 21st options request in a minute → 429.

Review authorization (`/admin/faceid/credentials…`):
- Employee approving his own pending → 403; admin without `approve_faceid_devices` list/approve → 403; tier admin revoke (super admin only) → 403; tier admin on another admin's or his own credential → 403; tier admin list filtered by an admin's `user_id` → 0 rows; no `public_key` / `credential_id` in responses; unknown id → 404; approve twice / reject after approve → 409.

Check-in assertion (`POST /attendance/check-in/confirm`, Face ID required):
- No assertion, assertion from the revoked old key, no UV, wrong origin, foreign challenge, assertion signed for intent N used on intent N+1, assertion over a registration challenge, other user confirming, other device of the same user confirming, expired challenge (intent alive), 500 KB `id`, wrongly typed fields → all 422 `faceid_failed` / `intent_invalid` / validation.
- Valid assertion → 201 `faceid_verified: true`; immediate double submit → 200 idempotent, no second row; sign counter stored (10) and regressions (3, 10) refused.
- Revocation is immediate: intent created before the revoke cannot be confirmed (422); new intents → 403.
- Gate for all types: `gps`, `mission`, `remote` intent without Face ID → 403 `faceid_not_registered`; mission and gps confirm without assertion → 422. Admin on-behalf: without reason 422, with reason 201 (no Face ID, as specified), admin without tier 403.

Web Push (`/push/subscriptions`), all refused 422 `push_endpoint_invalid` / validation: `http://`, foreign host, `evilfcm.googleapis.com`, `fcm.googleapis.com.evil.test`, userinfo both ways, backslash-`@` trick, explicit port (8443 and 443), `127.0.0.1`, decimal IP `2130706433`, `[::1]`, `169.254.169.254`, trailing dot, Greek-omicron homograph, punycode, `#` fragment trick, `%00` and `%2e` in host, 3000-char endpoint, non-base64url keys. Valid FCM endpoint 201; 13 subscriptions → 5 kept; unauthenticated 401; `DELETE` with another user's endpoint has no effect (scoped to the caller).

UX2 fixes (`phase4_ux2.sh`):
- UX2-01: whitespace/case/zero-width variants of a wrong code all count as failures; 5 → 429 block; correct spaced code refused while blocked; 5000-space password → 422.
- UX2-02: 4th sign-up for the same email (case changed) → 429; per-IP 429 exactly at the 50th request of the hour; `X-Forwarded-For` does not reset it; identical 429 body for existing and unknown emails.
- UX2-07: super admin code 429 `too_many_code_requests` byte-identical (except `retry_after_seconds` value) for a real super admin and an unknown email.
- UX2-08: while the super admin is temporarily blocked, 20 recovery requests produced 5 emails (= `otp_requests_per_hour`); every answer is the same 202 as for an unknown email.

Logs: WebAuthn failures log only the exception class and user id; no challenge, assertion, code or token found in `storage/logs/laravel.log`.

## Regression

- `phase4_parallel_faceid.php`, 200 employees each with a software passkey: 200 intents 201 (each with `faceid_options`), 200 confirms 201 + 200 simultaneous duplicate confirms 200; 0 errors, 200 rows, all `faceid_verified`, 0 duplicates. Confirm latency on `php artisan serve` (16 workers, 400 concurrent requests): p50 8.6 s, p95 16.6 s, p99 17.3 s, wall time 27 s (phase 3 without Face ID: 23 s) – built-in server queueing, not representative; k6 on the test instance still to do.
- `phase3_attendance.sh` (run with `faceid_required_for_checkin=false`, since it confirms without assertion; restored to `true` afterwards): 64 PASS, 3 FAIL that are time-of-day artefacts (run at 18:18 Accra, after `end_of_day_time`, so the "early check-out" checks do not apply) – not regressions.
- `phase3_clock.php` 20/20 PASS (same Face ID switch, it calls the service without assertion). `phase3_fixes.php` 14/14 PASS.
- Re-running the HTTP scripts needs the load users' attendances/intents/credentials reset and `php artisan cache:clear` (the Face ID script resets its own users 206–229).
