# Progress – Backend (v2)

Updated by the agents at the end of every work session.

## Current phase
**Phase 5 – Tasks, clients and reports: backend done – ready for backend-security-tester** (focus below). Phase 4 backend: no open finding.

### Phase 5 security fixes (2026-11-12) – `docs/reports/phase-5-security.md`
- **P5-01** global middleware `RejectControlCharacters`: any query/form/JSON key or value with NUL or another control character (tab, LF, CR allowed) → 422 `invalid_characters`, app-wide.
- **P5-02 rule (client names of a week)**: shown to super admins, `view_clients` holders and `receive_weekly_reports` holders (SPECS 7: the weekly report sent to the recipients is accompanied by each employee's client list); `view_reports` alone gets `clients_count` + `services` only. One helper `AdminReportController::canSeeWeeklyClients` used by `/admin/reports/weekly/submissions/{id}` (`week.clients`), `/admin/users/{id}/reports/weekly` (`clients`) and the weekly emails (recipients filtered by the same rule). Daily reports still require `view_clients`.
- **P5-03** files served `Content-Disposition: attachment; filename="report.pdf"` (fixed safe name) + CSP with `sandbox`; S3 pre-signed URLs force the same disposition; uploads stored with `ContentType: application/pdf`; PDFs containing `/JavaScript`, `/JS`, `/Launch`, `/EmbeddedFile(s)`, `/RichMedia` (also `#xx`-escaped) → 422 `pdf_active_content` (compressed object streams not inspected).
- **P5-04** weekly close counts and attaches, per employee, the latest version submitted before the close, even if replaced afterwards.
- **P5-05** PDFs attached only if raw total ≤ `email_attachments_max_mb` (default 20, max 28) and Base64 size (×4/3 + 3 % + 1 MB margin) ≤ `MAIL_PROVIDER_MAX_MB` (Resend 40). `report_deliveries.sent_at` is the claim; new `delivered_at` is set only when the provider accepted the email; after 3 failed tries the attachment email is re-queued as the link-only version.
- **P5-07** only the first version received after the close is forwarded (in-app + email); later replacements of the same employee-week are not re-sent – visible in the application (current version + history). Max 1 forward per employee-week.
- **P5-08** `docker/php.ini` `upload_max_filesize = 20M`, `post_max_size = 22M`; `report_pdf_max_mb` capped at 20 (default 10).
- P5-06 (suggestions shared free text): accepted, frontend renders as text (as P3-07). Q23 grace: owner question unchanged.
- **Round 2 (P5-09/10/11)**: structural PDF scanner `PdfActiveContentScanner` (stream data skipped → no false positives on images; Flate `/ObjStm` inflated; also rejects `/XFA`, `/SubmitForm`, `/ImportData`, `/OpenAction`/`/AA` → `/URI`/`/Launch`); C1 controls U+0080–U+009F → 422 `invalid_characters`. 402/402 tests. Ready for **backend-security-tester** re-test of P5-09..11.
- **Round 3 (P5-12/13)**: `PdfActiveContentScanner` rewritten as a tokenizer (literal strings with nesting/escapes, hex strings and comments skipped; `stream` only after an object dictionary's `>>`, data skipped by `/Length` (indirect resolved, `endstream` fallback); ambiguous structure → rejected). Object streams decoded (Flate ± PNG predictor, ASCIIHex, ASCII85); any other filter or decode error → rejected. Auto actions followed through references and `/Next` with a visited set. All samples of `phase5_pdf_samples.py` + `phase5_pdf_bypass_r2.py` covered by unit tests. 421/421 tests, phpstan clean. Ready for **backend-security-tester** re-test of P5-12/13.
- Contract changes (additive): 422 `invalid_characters` on any route, 422 `pdf_active_content` on the weekly upload; downloads are attachments; `week.clients` absent for `view_reports`-only admins. Ready for **backend-security-tester** re-test.

### Phase 5 backend – what exists (2026-11-12)
- **Schema** (reversible migration `2026_11_19_100000_create_reports_tables`): `tasks` (planned/done, `completed_at` = server time, CHECK done ⇔ completed_at), `clients` (name, free-text `service` with collapsed spaces, `service_key` = lower-case normalized grouping key, quantity ≥ 1), `daily_reports` (unique user/date snapshot, services JSON, `recomputed_at`), `weekly_report_submissions` (versions, unique `(user, week, version)`, partial unique "one current", sha256, `is_late`, `forwarded_at`), `monthly_reports` (unique month, private PDF path), `report_deliveries` (unique kind/ref/user per-recipient record).
- **Tasks / clients** (`/me/tasks`, `/me/clients`, employees only): reading never needs the check-in; every write is behind `checked-in` (P3-06) + `throttle:attendance`. Created for today's server work date; editable/deletable at any time (other users' rows → 404). Caps: `tasks_max_per_day` (100), `clients_max_per_day` (200), `client_quantity_max` (999). Service suggestions `GET /clients/services?q=` (all employees' services, one spelling per key, most used first; LIKE wildcards escaped).
- **Daily report** (`reports:daily`, every minute, run key per date, catch-up `report_catchup_days` = 7): at `daily_report_time` (17:00) a snapshot per active employee expected that day (from the account creation date) or with data; any later edit recomputes an existing snapshot. API views are always computed from live data.
- **Weekly summary** Monday → Friday with clients recap (`/me/reports/weekly`, admin `/admin/users/{id}/reports/weekly`).
- **Weekly PDF** (`/me/reports/weekly/submission` GET status / POST multipart `file`): PDF magic bytes (client type ignored), max `report_pdf_max_mb`, private disk, server-generated path, versions kept (max `weekly_report_max_versions` = 10), row lock per user. Window: report day `weekly_window_day` (Friday) `weekly_window_start` → `weekly_window_end`. Before the window opens an upload goes to the previous week only if that week has no report yet (and the account existed then), else 422 `window_not_open`. Late = after the personal deadline; deadline = Friday 17:00, or – when absent on Friday (not expected: leave/day off/holiday; or, setting `weekly_absent_friday_grace`, expected but no check-in) – 17:00 (window end time) of the next expected day. Anything received after the Friday close is forwarded at once (`ForwardWeeklySubmission` job: in-app + email, per-recipient records).
- **Reminder** `reports:weekly-reminder` at `weekly_reminder_time` (16:30) to expected employees without a submission (in-app + push), once per Friday, caught up until 17:00 only, per-recipient `reminder_deliveries`.
- **Close** `reports:weekly-close` at 17:00: "N of M received" + names missing, in-app + email to super admins and `receive_weekly_reports` admins (even if nobody sent); each employee's clients of the week in the email; PDFs attached while the total ≤ `email_attachments_max_mb` (30), otherwise the email points to the reports in the application (sign-in required – no bearer links in emails). Catch-up 7 days, never twice.
- **Monthly** `reports:monthly` on `monthly_report_day` (26) at `monthly_report_time` (19:00): period 26th previous month → 26th current month inclusive; DomPDF view `resources/views/pdf/monthly.blade.php` reproducing the template in English (blue "ASSISTANCE GHANA" / orange "by GIMA Services", title, 4 columns, late count red, "02"/"-", one service per line "02 Residence Permit", footer); file `Rapport_de_performance_<Mois>_<Année>.pdf` (French month of sending, no accents: Fevrier, Aout, Decembre); stored privately; emailed (attachment) only to active super admins + active admins listed in `monthly_report_recipient_ids`; in-app notice `monthly_report_emailed`. Not visible in the app. Catch-up 7 days, per-recipient records.
- **Admin views**: `GET /admin/reports/daily` (view_reports; task titles with view_tasks, client names with view_clients), `GET /admin/reports/weekly` and `GET /admin/reports/weekly/submissions/{id}` (view_reports **or** receive_weekly_reports; authorization before lookup; temporary signed URLs).
- **Notification kinds** (in-app + push copy): `weekly_report_reminder` (path `/reports/weekly`), `weekly_close` (`/admin/reports/weekly?date=`), `weekly_forward` (`/admin/reports/weekly/submissions/{id}`), `monthly_report_emailed`.
- **Libraries**: `barryvdh/laravel-dompdf` 3.1 (DomPDF), dev `smalot/pdfparser` (PDF text checks in tests).
- **Tests** (`tests/Feature/Reports`, frozen clock): 16:59:59 / 17:00 / 17:00:01 boundaries, 15:59:59 window, 16:29:59 / 16:30 / 17:00 reminder, 18:59:59 / 19:00 monthly, catch-up and "never twice", failed recipient retry, grace (leave, no check-in, setting off), versions cap, abuse (HTML renamed .pdf, MZ header, empty, oversize, path traversal name, tampered/expired URL), tiers per endpoint, PDF text content parsed. **Pest 380 green, Larastan 0 errors, Pint clean, migration rollback/migrate OK.**

### For attendance-frontend (phase 5)
Contract `docs/api/openapi.yaml` 0.5.0 (additive). Writes return 403 `checkin_required` before the check-in; send nothing time-related (work date and completion time are server-side). Use `GET /clients/services?q=` for the service autocomplete. Weekly upload: show `GET /me/reports/weekly/submission` (`can_submit`, `opens_at`, `deadline`, `is_late_now`, versions); `window_not_open` carries `opens_at`. Admin screens: notification paths above.

### For backend-security-tester (phase 5 focus)
Upload abuse (polyglot PDF/HTML, huge files, many versions, parallel uploads → version numbers), IDOR on tasks/clients/submissions, tier checks on the 4 admin endpoints and field filtering, signed file URLs, scheduler idempotency (parallel `reports:*` runs, failed recipient retry), monthly recipients (non-admin ids in the setting), late forwarding duplication, suggestion endpoint (LIKE injection, data exposure).

### Phase 4 security fixes + frontend requests (2026-11-12)
- P4-01/02/03 (Low) fixed with regression tests – see `docs/reports/phase-4-security.md` "Fixes applied". Phase 4 backend: no open Critical/High/Medium/Low finding.
- **Contract changes for attendance-frontend** (`docs/api/openapi.yaml` 0.4.1, all additive):
  - `POST /push/subscriptions` may answer **409 `push_endpoint_conflict`** (endpoint owned by another account on another device).
  - `GET /admin/faceid/credentials` items carry `registration {device_id, registered_at, ip, platform, browser, user_agent, device_first_seen_at}`.
  - New in-app/email notification kind `faceid_device_registered` for the account owner.
  - `GET /admin/settings`: each setting has `min`, `max`, `unit` (+ `item_min`/`item_max` for int_list), read from validation rules.
  - `GET /me` (and login user): `password_min_length`.
  - `invalid_credentials` 401 carries `remaining_attempts` **only on a known device** of the account (unknown devices and unknown emails get the identical body without it – no enumeration).
  - 401 with **`code: account_blocked`** on the next request after the account was blocked, locked or deleted (token hashes kept 30 days in `revoked_sessions`).
- UX2-17 (frontend phase-2 UX report) is fixed on the backend (plain-text emails unescaped, test `escapes names only in the HTML part of emails (UX2-17)`); the frontend report can mark it Fixed.

### Phase 4 backend – what exists (2026-11-12)
- **Libraries**: `web-auth/webauthn-lib` 5.3 (reference PHP WebAuthn implementation, maintained, framework-agnostic, full W3C ceremony checks: challenge, origin, RP ID hash, UP/UV flags, signature, counter, backup flags; chosen over laragear/webauthn because we need device binding, admin approval and a challenge bound to the check-in intent, which laragear's login-oriented flow does not model). `minishlink/web-push` 9 (standard PHP Web Push, VAPID + aes128gcm). Composer also refreshed a few minor framework/symfony versions.
- **Schema** (reversible): `webauthn_credentials` (public key only, base64url credential id unique, user handle, sign count, transports, backup flags, device link, status `pending|approved|revoked` with partial unique indexes "one approved / one pending per user", approved_by/at, revoked_by/at/reason), `webauthn_challenges` (uuid, random 32 bytes, purpose `registration|checkin`, user + device, unique `checkin_intent_id`, expiry, consumed_at), `attendances.webauthn_credential_id`, `push_subscriptions` (endpoint hash unique, keys, device link).
- **Face ID flow**: `POST /me/faceid/register/options` → `navigator.credentials.create()` → `POST /me/faceid/register`. Platform authenticator, `userVerification: required` (also checked on the UV flag), attestation `none`, RP ID/origins from env (`WEBAUTHN_RP_ID`, `WEBAUTHN_ORIGINS`). First credential of an account approved automatically (`faceid_first_device_auto_approve`, default on – registration happens at first sign-in after the emailed code); any later one is **pending**, replaces an earlier pending request, notifies `approve_faceid_devices` holders (in-app + push). Approval revokes the previous credential; reject; **super admin revoke** of any credential. Audit log for registration, failure, approval, rejection, revocation.
- **Check-in**: `faceid_required_for_checkin` now **default true** (old `faceid_required` merged by the migration). Intent refused first with 403 `faceid_not_registered | faceid_pending_approval | faceid_other_device`; otherwise the intent returns `faceid_options` (request options whose challenge is bound to that intent, user and device, allowCredentials = the approved credential of this device). Confirm verifies the assertion (approved credential of that user **and** that device, UV, origin, RP ID, signature, counter not going back), then consumes the challenge in the same transaction as the intent claim (no replay; a double submit still returns 200 with the existing attendance). Applies to gps, mission and remote. Admin on-behalf check-in unchanged (reason, no Face ID). `GET /attendance/today` adds `faceid_state`.
- **Web Push**: `GET /push/public-key`, `POST/DELETE /push/subscriptions` (any role; https endpoints on known push services only – no SSRF; max `push_max_subscriptions_per_user`, oldest dropped; endpoint moved to the account signed in now; removed on sign-out). Every stored in-app notification queues a `SendWebPush` job (listener `PushInAppNotification`, after commit): 08:05 reminder placeholder replaced; Face ID requests/decisions, mission check-ins, early check-outs, sign-ups, alerts are pushed too; phase 5 weekly reminders will follow automatically. Job: VAPID via `PushTransport` (fake in tests), minimal payload (kind, title, body, path, tag), 404/410 → subscription deleted, never retried (no duplicate), skipped without keys / when `push_enabled` is off / for inactive accounts. `php artisan attendance:vapid-keys` generates keys.
- **New settings**: `faceid_challenge_ttl_seconds` (120), `faceid_first_device_auto_approve` (true), `push_enabled` (true), `push_ttl_seconds` (3600), `push_max_subscriptions_per_user` (5); `faceid_required` removed (merged).
- **Tests**: software authenticator (`tests/Support/webauthn.php`, ES256, real CBOR/attestation/signatures): registration, replayed/expired/foreign challenge, no UV, wrong origin/RP/type, pending/approval/reject/revoke per tier, check-in assertion, foreign key, other user's credential, revoked (before and between steps), pending, other device, replay on a new intent, double submit, sign count regression, mission with Face ID, admin fallback; push subscriptions, SSRF filter, cap, sign-out, delivery/expiry with a fake transport, in-app → push. **Pest 335 green (with the UX2 fixes), Larastan 0 errors, Pint clean, migrations rollback/migrate OK.**
- **Docs**: `docs/api/openapi.yaml` v0.4.0, `docs/DEPLOYMENT.md` §2.3 (RP ID must be the final domain) and §2.4 (VAPID), `.env.example`.

### For attendance-frontend (phase 4) – new endpoints and contract changes
- **Breaking**: Face ID is now required by default. `POST /attendance/check-in/intent` may return 403 `faceid_not_registered` / `faceid_pending_approval` / `faceid_other_device`; show the registration or "waiting for approval" screen. Use `faceid_state` from `GET /attendance/today` or `GET /me/faceid`.
- Registration at first sign-in (after the code change): `POST /me/faceid/register/options` → `navigator.credentials.create({ publicKey: PublicKeyCredential.parseCreationOptionsFromJSON(public_key) })` → `POST /me/faceid/register { challenge_id, credential: credential.toJSON(), name }`. Codes: 422 `challenge_invalid` (restart), `faceid_failed`, 409 `faceid_already_registered`.
- Check-in: intent → `navigator.credentials.get({ publicKey: parseRequestOptionsFromJSON(faceid_options.public_key) })` → confirm `{ intent_id, assertion: credential.toJSON() }`. After `faceid_failed`, start again from the intent. Binary fields are base64url (use a polyfill when `toJSON`/`parse…FromJSON` are missing on older Safari).
- Admin: `GET /admin/faceid/credentials?status=pending`, `POST /admin/faceid/credentials/{id}/approve|reject` (tier `approve_faceid_devices`), `POST …/revoke` (super admin). Notification path `/admin/faceid-devices`.
- Push: `GET /push/public-key` → `pushManager.subscribe({ userVisibleOnly: true, applicationServerKey })` → `POST /push/subscriptions` (subscription.toJSON()); `DELETE /push/subscriptions {endpoint}` when the user disables it. Re-subscribe after each sign-in (sign-out deletes the device's subscriptions). Service worker payload: `{kind, title, body, path, tag}`; open `path` on click.

### Phase 2 UX fixes (backend, from frontend `docs/reports/phase-2-ux.md`) – for attendance-frontend
- **UX2-01 fixed**: emails are trimmed (global TrimStrings) and, for generated codes (identification code while `must_change_password`, super admin one-time code), all whitespace is removed before verification; a pasted space no longer counts as a failure. Chosen passwords are still compared exactly.
- **UX2-02 fixed**: `signup_requests_per_hour` per IP default **50** (max 1000) + new `signup_requests_per_email_per_hour` (3). 429 `too_many_requests` now carries `retry_after_seconds` and a `Retry-After` header; message suggests asking an administrator.
- **UX2-07 fixed**: `POST /auth/super-admin/code` returns **429 `too_many_code_requests`** with `retry_after_seconds` / `Retry-After` when the (email, IP) or IP limit is reached – identical for unknown emails (no enumeration). Show "Use the last code you received or wait N minutes".
- **UX2-08 fixed**: the recovery link is also sent while the super admin is **temporarily blocked** (not only locked); confirming it clears the block. Still rate limited and same 202 answer.
- **UX2-17 fixed**: plain-text email parts are no longer HTML-escaped (escaping only in the HTML part). `FRONTEND_URL` must be set per environment (deploy).
- **UX2-18 fixed**: `last_login_at` was already recorded for one-time-code sign-ins; it is now also returned on `/me` (own account) so the super admin's own row is filled; `employee_type` is `null` for admins and super admins (**contract change**: nullable).
- **UX2-11**: unchanged on purpose (generic message for pending accounts, anti-enumeration).
- Tests: `tests/Feature/Ux/Phase2UxFixesTest.php` + updated sign-up / super admin limit tests.

### For backend-security-tester (phase 4 focus)
Assertion replay (same and new intent), foreign credential / other user's authenticator, revoked or pending credential, credential of another device of the same user, sign counter regression, UV flag off, origin/RP ID spoofing, registration challenge reuse/expiry/cross-user, approval tier checks (admin on admin, self-approval, approve after reject, parallel approvals vs. unique index), super-admin-only revoke, push endpoint SSRF (host suffix tricks, redirects), subscription hijack (deleting/moving another user's endpoint), payload content (no sensitive data), push job idempotency. Re-test P3-04.

### Phase 3 security fixes (2026-11-12)
- P3-01: new setting `max_remote_days_per_week` (default 2, see Q18); employee self-selection takes effect from the next day (never the same day) and notifies every `manage_work_schedules` holder (in-app + email, queued). **Contract change for attendance-frontend**: after `POST /me/work-schedule` the choice is in `upcoming[0]`, `current` stays as before today; `max_remote_days_per_week` added to `GET /me/work-schedule`.
- P3-02: `reminder_deliveries` table (unique kind/user/date): a retried reminder run skips employees already reminded; one failing send no longer stops the others (run marked failed, retried for the remaining ones only). `ScheduledRunner` takes over a `running` row older than `scheduled_run_stale_minutes` (new setting, default 15) for every scheduled job.
- P3-03: confirmation refused (`422 intent_invalid`) when the token's device differs from the device that created the intent.
- P3-07: email templates use Blade escaping; regression tests with HTML payloads (mission reason, alert title/body).
- Pest 275 green, Larastan 0 errors, Pint clean, migrations reversible.
Phase 1 backend: all findings closed (final check P1-15 … P1-20 done). Phase 0: P0-01 waits for the owner.

### Phase 3 backend – what exists
- **Schema** (reversible migration `2026_11_15_100000_create_attendance_tables`): `attendances` (unique `(user_id, work_date)`, timestamptz, check-in type `gps|mission|remote|admin_on_behalf`, position/accuracy/distance, `is_late` + `late_after` rule snapshot, `day_status` snapshot, mission status/validator/note, `checked_in_by`, check-out time/type `manual|auto_midnight`, check-out position/distance, early leave + reason + `end_of_day` snapshot, CHECK constraints), `checkin_intents` (single-use, short-lived), `checkin_attempts` (refused: `out_of_perimeter`, `low_accuracy`), `work_schedules` (versioned by `effective_from`), `holidays`, `leaves`.
- **Domain** (`app/Domain/Attendance`): `WorkCalendar` (server time, Accra work date, day status: holiday > leave > non-working day > day off > remote > working), `AttendanceService`, `AttendancePresenter` (fields per tier), `Geo` (haversine), `CheckinAssertionVerifier` interface (phase 4 binds WebAuthn; phase 3 binds `UnavailableAssertionVerifier`).
- **Check-in flow** (two steps, Face ID ready): `POST /attendance/check-in/intent` (gps: server perimeter check with accuracy; mission: reason; remote: only on remote days) → `POST /attendance/check-in/confirm` (`assertion` from phase 4). `faceid_required_for_checkin` (default **false**) enforces a verified assertion. Lateness and work date use the server time of the confirmation. Claim + `INSERT … ON CONFLICT DO NOTHING` in one transaction: double submits return 200 `created: false` with the same row.
- **Check-out** from anywhere with optional position (distance, `check_out_at_office`, "position not available"), reason required before `end_of_day_time` (setting), early leave notifies `view_lateness` holders (in-app, queued).
- **Mission** check-in pending approval (`approve_mission_checkins`): list, approve, reject (once), approvers notified, employee notified. **Admin fallback check-in** (`checkin_for_employee`) with reason, logged as warning.
- **Schedules**: employee selects days off / remote days once (`POST /me/work-schedule`, max `max_days_off_per_week`, only `working_days`), then only `manage_work_schedules` admins change them from an effective date (past never rewritten). **Holidays** (super admin / `manage_leaves`), **leaves** (`manage_leaves`, no overlap).
- **Admin views**: `GET /admin/attendance/day` (every active employee with day status + attendance), `GET /admin/users/{id}/attendance`, `GET /admin/attendance/attempts` (`view_lateness`); lateness fields only with `view_lateness`, raw coordinates only for super admins.
- **Check-in gate**: middleware alias `checked-in` (`EnsureCheckedInToday`) → 403 `checkin_required` for employees without today's check-in on expected days. To be applied to the phase 5 routes (tasks, clients, reports); never on auth/profile/notification/attendance routes.
- **Scheduled jobs** (every minute, `scheduled_runs` keys, catch-up, never twice): `attendance:auto-checkout` (closes open days at `auto_checkout_time`, default 00:00 next night, "check-out forgotten"; check-out never before check-in), `attendance:checkin-reminder` (at `checkin_reminder_time`, expected employees without check-in, stored notification + queued `SendWebPush` placeholder; catch-up the same day until `end_of_day_time` only).
- **New settings**: `checkin_max_accuracy_m` (50), `checkin_intent_ttl_seconds` (120), `faceid_required_for_checkin` (false), `working_days` ([1..5]), `checkin_attempts_per_minute` (20, named limiter `attendance`).
- **Load test**: `php artisan attendance:load-fixtures --count=200` (refused in production) + `tests/load/checkin.js` (k6) / `tests/load/parallel_checkin.sh` (curl). Local result (PHP built-in server, 32 workers, local PostgreSQL): **200 parallel check-ins each with a parallel double confirmation → 200 × 201, 200 × 200, 0 error, 0 duplicate** (20 s wall time on the dev server; to re-run with k6 on the test host).
- **API contract**: `docs/api/openapi.yaml` v0.3.0 (all phase 3 routes, `Attendance`, `DayStatus`).
- **Checks**: Pest 264 tests green on PostgreSQL; Larastan level 8: 0 errors; Pint clean; migration rollback/migrate OK.

### For attendance-frontend (phase 3)
- Check-in = intent then confirm (send nothing time-related). Handle 422 codes `low_accuracy` (retry automatically, show `accuracy_m`), `out_of_perimeter` (show `distance_m`), `not_remote_day`, `reason_required`, `intent_invalid` (restart), `faceid_failed`; 409 `already_checked_in` (refresh today). Use `server_timestamp_ms` from `/attendance/today` to sync the displayed clock.
- 403 `checkin_required` on phase 5 routes = show the check-in screen. 403 `employees_only` on attendance routes for staff accounts.

### For backend-security-tester (phase 3 focus)
Perimeter bypass attempts (validation, huge/negative accuracy, mission/remote abuse), intent replay/expiry/other user, parallel double submit (`tests/load/parallel_checkin.sh`), tier checks on every admin attendance route, field filtering, mission self-approval, schedule re-selection race, scheduled job idempotency.

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
| 3 – Attendance (manual) | Backend done | phase-3-security (fixes re-tested, no Critical/High) | P3-04 closed by phase 4 (re-test) |
| 4 – PWA, Face ID, notifications | Backend done | phase-4-security pending | Ready for backend-security-tester; frontend can start |
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
Phase 3 business questions (safe default implemented, configurable where possible):
- **Q10 – GPS accuracy rule**: the check-in is refused while the reported accuracy is worse than `checkin_max_accuracy_m` (50 m), then accepted if the measured position is within the radius (50 m). Stricter alternative: require distance + accuracy ≤ radius (often impossible indoors). Confirm.
- **Q11 – Working days**: default Monday–Friday (`working_days`). Is Saturday worked?
- **Q12 – Who checks in**: attendance is for employees/interns only; admins and super admins do not check in. Confirm.
- **Q13 – Check-in on a non-expected day** (day off, holiday, leave, weekend): allowed, never late, not reminded. Confirm.
- **Q14 – Tiers**: early check-out notifications and refused attempts go to `view_lateness` holders; holidays are managed by super admins and `manage_leaves` admins. Confirm or name other tiers.
- **Q15 – Rejected mission check-in**: the attendance stays (marked `rejected`, employee notified); it is not deleted and the employee keeps access for the day. What should a rejection mean for reports (absence?)
- **Q16 – Lateness time**: the time of the confirmation (after Face ID) is used, not the time of the position check (a few seconds earlier). Confirm.
- **Q18 – Remote days per week (P3-01)**: an employee can self-select at most `max_remote_days_per_week` remote days (default 2); the choice applies from the next day and admins with `manage_work_schedules` are notified. Confirm the number (contract-dependent?) and whether remote days chosen by the employee should need admin validation.
- **Q17 – Office GPS coordinates and holiday list** (owner input, PLAN §8): defaults 5.595223, -0.216703 in use; please provide the coordinates measured on site and the holiday list.
- Note: `faceid_required` and `faceid_required_for_checkin` merged in phase 4 (one setting, default on).

Phase 5 business questions (safe default implemented, configurable where possible):
- **Q23 – "Absent on Friday"**: grace (submit on the next working day without being late) is given when the employee was not expected on Friday (leave, day off, holiday) and, by default, also when expected but without check-in (`weekly_absent_friday_grace`). The grace ends at the window end time (17:00) of the next expected day. Confirm both.
- **Q24 – Weekly report recipients**: notifications/emails go to super admins + admins holding `receive_weekly_reports`; viewing in the dashboard is allowed with `view_reports` or `receive_weekly_reports`. Confirm.
- **Q25 – Links instead of attachments (> 30 MB)**: the email points to each report in the application (sign-in required) rather than to bearer download links. Confirm.
- **Q26 – Monthly PDF**: monthly "Clients" = number of client entries; "Client services" = sum of quantities per service; rows = active employees/interns + anyone with lateness or clients in the period, sorted by last name; French month names without accents in the file name (Fevrier, Aout, Decembre). Lateness counts every late check-in (rejected missions included, see Q15). Confirm.
- **Q27 – Upload before the window**: a report cannot be sent before Friday 16:00 (except a missing report of the previous week). Should early uploads (e.g. Thursday) be allowed?

Phase 4 business questions (safe default implemented, configurable):
- **Q19 – First Face ID registration**: approved automatically (setting `faceid_first_device_auto_approve`); every later device (new phone, Face ID reset, revoked by the super admin) needs an `approve_faceid_devices` admin. Should the very first registration also be approved by an admin?
- **Q20 – Push for every notification**: SPECS 8 lists push only for the 08:05 reminder and the Friday 16:30 reminder; the backend now pushes a copy of every in-app notification (setting `push_enabled` on/off for all). Confirm, or list the kinds that must stay in-app only.
- **Q21 – Revoke by the tier holder**: only super admins revoke an approved credential; `approve_faceid_devices` admins approve/reject pending ones. Confirm.
- **Q22 – Final domain** (owner input): needed for `WEBAUTHN_RP_ID` before the pilot; passkeys created on the test domain will not work in production.

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
- 2026-11-12 – Phase 5 backend: tasks, clients (service suggestions), daily report 17:00, weekly summary, weekly PDF upload (window, versions, late forwarding, 16:30 reminder, 17:00 close), monthly PDF of the 26th (DomPDF, template in English). 380 tests green. Ready for backend-security-tester.
- 2026-11-12 – Phase 4 backend: Face ID (WebAuthn passkeys: registration, admin approval of new devices, revoke, assertion bound to the check-in intent, required by default), Web Push (VAPID subscriptions, queued delivery, expired cleanup, push copy of every in-app notification). 326 tests green. Ready for backend-security-tester.
- 2026-11-12 – Phase 2 UX backend fixes UX2-01, 02, 07, 08, 17, 18 (UX2-11 kept). 335 tests green.
- 2026-11-12 – Phase 3 security fixes P3-01 (remote-days cap, next-day effect, admin notification), P3-02 (per-recipient reminder records, stale run takeover for all jobs), P3-03 (intent bound to device), P3-07 (escaping tests). 275 tests green. Ready for re-test.
- 2026-11-12 – Phase 3 backend: attendance schema, two-step check-in (GPS perimeter + accuracy, mission, remote, admin fallback), check-out with distance and early-leave reason, schedules/holidays/leaves, admin views per tier, check-in gate, auto check-out and 08:05 reminder jobs, load test (200 parallel: 0 error, 0 duplicate). 264 tests green. Ready for backend-security-tester.
- 2026-11-12 – P1-15 (purge floor 12 months + no EXECUTE for runtime role, purge on owner connection), P1-16 (known device evicts oldest unknown code, critical log), P1-17 (other codes cancelled on sign-in), P1-18 (atomic upsert limiter table), P1-19 accepted. 188 tests green, PHPStan clean.
- 2026-11-12 – Phase 1 security fixes: P1-01 … P1-09, P1-10, P1-11, P1-13 fixed with regression tests (182 tests green); SPECS §4 aligned (super admin one-time code); ready for re-test.
- 2026-11-12 – Phase 1 backend complete (schema, settings, roles/tiers, sign-up/approval/codes, super admin OTP + recovery, device lockout, sessions, profile, admin user management, activity logs, install command); phase 0 findings P0-02/03/04 fixed; CI on Postgres 17.
- 2026-11-12 – Phase 0 backend: legacy v1 removed, Laravel 13 skeleton, security baseline, Docker, CI.
- Specifications, agents and workflow added to the repository.
- Switched from native app (Capacitor) to PWA; automatic check-in now based on office Wi-Fi detection.
- Wi-Fi detection and automatic check-in removed; check-in = GPS perimeter + Face ID (passkey); fixed-time reminder at 08:05.
