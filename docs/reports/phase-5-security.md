# Phase 5 – Security report (tasks, clients, reports)

Date: 2026-11-12. Tester: backend-security-tester. Branch `claude/upbeat-noether-f7defe`.
Environment: local only. Own database `attendance_sec5` (not `attendance_e2e`). Production-mode server
`DB_DATABASE=attendance_sec5 APP_ENV=production APP_DEBUG=false QUEUE_CONNECTION=sync MAIL_MAILER=log php artisan serve --port=8183`.
Frozen-clock checks run in-process with fake mail/storage. No real email or push was sent.

Scripts:
- `tests/security/Phase5SecurityTest.php` – `DB_DATABASE=attendance_sec5 vendor/bin/pest tests/security/Phase5SecurityTest.php` (23 checks; the 4 failing ones are P5-01, P5-02 (x2), P5-04).
- `tests/security/phase5_fixtures.php` + `tests/security/phase5_http.sh` – parallel cap races, invalid/oversized JSON, polyglot upload and file response headers over HTTP.

## Findings

### P5-01 – NUL bytes silently truncate stored text (acknowledged value differs from stored value) – Low
- Endpoints: `POST/PATCH /me/tasks`, `POST/PATCH /me/clients` (title, name, service, `service_key`).
- Steps: checked-in employee, `POST /api/me/tasks {"title":"a\u0000b"}`.
- Observed: 201, response `title: "a\u0000b"`, database row `title = "a"` (pdo_pgsql cuts at NUL). Same for client `name`/`service`/`service_key` (`"s\u0000x"` stored as `"s"` → grouped with service "s" in reports). Test `P5-01 stores exactly what it acknowledges`.
- Expected: 422 (or NUL stripped) and the response equal to what is stored.
- Fix: reject control characters (`regex:/^[^\x00-\x08\x0B\x0C\x0E-\x1F\x7F]*$/u` or a global middleware stripping `\0` from input) on every free-text field.

### P5-02 – Client names returned by the submission endpoint without `view_clients` – Medium
- Endpoint: `GET /api/admin/reports/weekly/submissions/{id}` (`AdminReportController::submission`).
- Steps: admin holding only `receive_weekly_reports` (or only `view_reports`) calls the endpoint for any submission.
- Observed: 200 with `week.clients[]` (client names, services, quantities). The same admin gets `clients` removed on `/admin/users/{id}/reports/weekly` and `/admin/reports/daily`. Tests `P5-02` / `P5-02b`.
- Expected: one consistent rule. Either client names require `view_clients` everywhere (unset `week.clients` here, keep `services` and `clients_count`), or the owner confirms that SPECS 7 "report accompanied by the clients list" grants client names to every weekly-report recipient – then document it and apply the same rule to `userWeekly`. Note the weekly emails (`WeeklyReportService::deliver`) also list client names to every `receive_weekly_reports` holder.
- Fix: filter `week.clients` with `ViewClients` (and in the email lines), or record the owner decision in PROGRESS (new question).

### P5-03 – Polyglot `%PDF-` files accepted and served inline from the API origin – Info
- `isPdf()` only checks the `%PDF-` prefix; `%PDF-1.4\n<html><script>…` is accepted (file name `../../x.html` becomes display name `x.html`). Served with `Content-Type: application/pdf`, `nosniff`, `Content-Disposition: inline`, and the global `Content-Security-Policy: default-src 'none'` – no script execution path found. On S3 (Supabase) the pre-signed URL is served from the storage domain.
- Suggestion (defence in depth): add `sandbox` to the CSP of `/files/*`, send `Content-Disposition: inline; filename="report.pdf"`, force `ContentType: application/pdf` + `ContentDisposition` on S3 uploads, and optionally reject PDFs with `/JavaScript`, `/JS`, `/Launch`, `/EmbeddedFile` (staff open these files in desktop readers from the email attachments).

### P5-04 – An on-time report replaced after 17:00 disappears from the 17:00 count – Low
- Command: `reports:weekly-close` (`CloseWeeklyReports`) keeps only `is_current` rows with `submitted_at <= close`.
- Steps: upload v1 at 16:10, v2 at 17:00:30 (or any replacement after the close, before the job runs late/catch-up), run the close at 17:01.
- Observed: "Weekly reports: 0 of 1 received", employee listed as "Not received", the on-time PDF is not attached; v2 is then forwarded as late. Test `P5-04`.
- Expected: count the latest version submitted before the close (`max(version) where submitted_at <= close`), attach that one; late replacements forwarded separately.

### P5-05 – Attachment threshold of 30 MB exceeds provider limits once Base64-encoded – Medium
- `WeeklyReportService::deliver`: attach while raw total ≤ `email_attachments_max_mb` (default 30, max 40).
- Base64 grows the payload by ~37 %: 30 MB raw ≈ 41 MB on the wire, above Resend's 40 MB per email (and most SMTP relays: 25–35 MB). The delivery is recorded in `report_deliveries` when the mail is **queued**, so a provider rejection in the queue worker is never retried with the "too large, open in the app" variant: the 17:00 email (or a late forward) is lost for every recipient while the job reports success.
- Fix: compare `ceil(total * 4 / 3) + margin` with the limit (or set default 20 / max 28), and on a permanent send failure re-send the link-only version (or claim the delivery only after the mail job succeeded).

### P5-06 – Weekly suggestions share other employees' free text – Info
- `GET /clients/services` returns only `service` + `service_key` (no client names, no user ids – verified), LIKE wildcards escaped (`%`, `_` literal), SQL metacharacters harmless, admins refused (EnsureEmployee). But the label shown is the most-used spelling across all employees: one employee can make an arbitrary string (e.g. HTML, a client's name typed in the service field) the suggestion for everybody by entering it many times (bounded by 200 clients/day). Frontend must render it as text (as for P3-07); consider a max label length in the suggestion list and an admin-curated list later.

### P5-07 – Late re-uploads re-forward to every recipient – Info
- Every version uploaded after the close triggers `ForwardWeeklySubmission` (one email per recipient per version). Bounded by `weekly_report_max_versions` (10) → max 10 emails per recipient per employee-week. Acceptable; consider forwarding at most once per N minutes per employee-week.

### P5-08 – `report_pdf_max_mb` can exceed PHP limits – Info
- Setting max 50 MB while `docker/php.ini` has `upload_max_filesize = 10M`, `post_max_size = 12M`. Raising the setting has no effect (uploads fail with 422/413). Either cap the setting at 10 or derive the PHP limits from it in the deployment.

### Q23 grace (by design, note for the owner)
- Expected on Friday, no check-in, upload Saturday → `is_late = false` with `weekly_absent_friday_grace` on (verified; off → late). An employee who skips the Friday check-in gets until Monday 17:00 without the late flag (the absence itself is still visible). The owner should confirm Q23.

## Summary

| ID | Title | Severity | Status |
|---|---|---|---|
| P5-02 | Client names on submission endpoint without `view_clients` | Medium | Fixed |
| P5-05 | 30 MB attachment threshold vs Base64/provider limit, lost delivery | Medium | Fixed |
| P5-01 | NUL bytes truncate stored text | Low | Fixed |
| P5-04 | On-time report replaced after close not counted | Low | Fixed |
| P5-03 | Polyglot PDF accepted (served with CSP none) | Info | Fixed (hardened) |
| P5-06 | Suggestions share other employees' free text | Info | Accepted (rendered as text) |
| P5-07 | Late versions each re-forwarded | Info | Fixed |
| P5-08 | PDF size setting above PHP limits | Info | Fixed |

No Critical or High finding.

## Tests that passed
- **Check-in gate (P3-06 re-test: Fixed)**: all 7 write routes (task/client create, update, delete; weekly upload) → 403 `checkin_required` before check-in on an expected day; reads allowed.
- **IDOR**: another user's task/client PATCH/DELETE → 404, rows unchanged; non-existent id → 404; `?user_id=` on own lists ignored.
- **Mass assignment / server time**: `user_id`, `work_date`, `status`, `completed_at`, `service_key` in payloads ignored; `completed_at` = server time; weekly `submitted_at`, `is_late`, `week_start` in the payload ignored, lateness from server clock (17:00:01 → late).
- **Limits**: quantity 0, -1, 1000, 1.5, "1e3", "abc", null, PHP_INT_MAX, array → 422; daily caps enforced, including **30 parallel requests over HTTP → exactly 5 created** (tasks and clients). Invalid JSON → 422; 3 MB JSON → 422. Unicode (RTL override, BOM, 500 × "é", invalid UTF-8) → never 500.
- **Uploads**: before the window → 422 `window_not_open`; MZ header, empty, HTML with `%PDF-` not at offset 0, leading space → 422; oversize (10 MB + 1 KB) → 422; path traversal name `../../../etc/<b>passwd</b>.php` → server path `weekly-reports/<uid>/<week>/<uuid>.pdf`, sanitized display name; 11th version → 422 `too_many_versions`, exactly 10 files stored; admins cannot upload (EnsureEmployee).
- **Signed URLs**: valid → 200 `application/pdf`, `nosniff`, CSP `default-src 'none'`, `X-Frame-Options: DENY`, `Cache-Control: no-store`; other user's path, no signature, forged signature, edited `expires`, host swapped, expired (TTL 10 min + 1) → 404. URLs are bearer links valid until expiry (by design, short TTL; never emailed).
- **Admin tiers**: employee, tierless admin, admin with only `view_clients`/`view_tasks` → 403 on the 4 endpoints (including unknown submission id: no existence oracle); `view_reports` only → task titles and client names removed from daily and user weekly.
- **Jobs (frozen clock)**: weekly close missed on Friday and run twice on Saturday → one email per active recipient, blocked admin excluded; reminder not sent after the close; monthly missed on the 26th, run twice on the 27th → one email to the super admin + the listed active admin only (employee id and blocked admin id in `monthly_report_recipient_ids` ignored).
- **Monthly PDF**: period 26 Oct → 26 Nov inclusive (26 Oct counted, 25 Oct excluded); HTML in employee names and services rendered as literal text (`<img src="file:///etc/passwd">`, `<link href=http://127.0.0.1:1/…>` not fetched, no file content); DomPDF `enable_remote` false, `enable_php` false (package defaults; recommend publishing `config/dompdf.php` to pin them and set `enable_javascript` false).
- **Emails**: `<script>` in client/employee names escaped in the HTML part of `WeeklyReportsMail`.

## Fixes applied (attendance-backend, 2026-11-12)

Regression tests: `tests/Feature/Reports/Phase5FixesTest.php` (10 tests); `tests/security/Phase5SecurityTest.php` 23/23 green.

- **P5-01** – Global middleware `App\Http\Middleware\RejectControlCharacters`: NUL / C0 controls (except tab, LF, CR) / DEL in any query, form or JSON key or value → 422 `invalid_characters`.
- **P5-02** – Rule decided from SPECS 7 (weekly report accompanied by each employee's client list): client names of a week are visible to super admins, `view_clients` and `receive_weekly_reports` holders; `view_reports` alone gets counts and services only. Single helper `AdminReportController::canSeeWeeklyClients`, applied to the submission endpoint, the user weekly endpoint and the weekly email recipients. The security test `P5-02` was updated to this decision (receiver sees names on both endpoints); `P5-02b` (view_reports only) passes.
- **P5-03** – `/files/*`: `Content-Disposition: attachment; filename="report.pdf"`, CSP `default-src 'none'; sandbox; …`; S3 pre-signed URLs set `ResponseContentDisposition`; uploads stored with `ContentType: application/pdf`; PDFs with `/JavaScript`, `/JS`, `/Launch`, `/EmbeddedFile(s)`, `/RichMedia` (including `#xx` escapes) → 422 `pdf_active_content`. Limit: compressed object streams are not decoded.
- **P5-04** – `reports:weekly-close` takes, per employee, the highest version with `submitted_at <= close` (not `is_current`).
- **P5-05** – Attach only if raw ≤ `email_attachments_max_mb` (default 20, max 28) and `ceil(raw × 4/3 × 1.03) + 1 MB ≤ MAIL_PROVIDER_MAX_MB` (40). New `report_deliveries.delivered_at`, set by `WeeklyReportsMail::send()` only after a successful send; `failed()` (after 3 tries) re-queues the link-only version for the same recipient and delivery.
- **P5-06** – Accepted: labels are plain text, the frontend renders them as text.
- **P5-07** – Only the first version received after the close is forwarded; later replacements are visible in the application only.
- **P5-08** – `docker/php.ini` 20M / 22M; `report_pdf_max_mb` max 20.
