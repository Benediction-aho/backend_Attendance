# Security test – changes of 2026-11-14 (admin attendance, CORP on file links, activity reports)

Commits tested (local, not deployed), branch `claude/upbeat-noether-f7defe`: `54f9133`, `1110430`, `d521417`.
Environment: LOCAL only. PostgreSQL 16 in the container, scratch database `attendance_chg1114`, API served by
`php -S 127.0.0.1:8391` with `APP_ENV=production APP_DEBUG=false MAIL_MAILER=log QUEUE_CONNECTION=sync PRIVATE_FILES_DISK=local`,
storage in a scratch directory. No production host, no Supabase, no real e-mail. No application code changed.
Users: 1 super admin (SA), 3 employees (e1–e3), admins `aAll`/`aAll2` (all 22 permissions), `aNone` (none), `aRep`
(view_reports), `aRecv` (receive_weekly_reports), `aLogs` (view_logs), `aSetA` (manage_settings_attendance), `aSetR`.
`admin_attendance_starts_on` = the migration date (2026-11-14), `working_days` 1–7 so that the test day was a working day.
Test scripts were kept in the session scratch directory (not committed, per instructions).

## Findings

| ID | Sev. | Title | Affected |
|---|---|---|---|
| CHG-01 | Medium | An admin holding "Settings – Attendance" can switch off Face ID, move the office and the lateness hour, then check in for themselves from anywhere, never late | `PUT /api/admin/settings` (`SettingDefinitions::SUPER_ADMIN_ONLY`) |
| CHG-02 | Low | An admin holding `manage_leaves` can declare today a public holiday and so exempt themselves (and everyone) from check-in, lateness and the check-in gate | `POST /api/admin/holidays` (gate `manage-holidays`) |
| CHG-03 | Info | Role judged as it is today: after a promotion, past attendance and activity reports leave the admins' view (employee → admin) or every list (admin → super admin) | `AttendanceScope::rolesVisibleTo`, `visibleRows` |
| CHG-04 | Info | Emptying `admin_attendance_starts_on` during a day takes effect for that same day: admins already checked in can no longer check out nor read their own history/activity reports | `EnsureTracksAttendance`, `AttendanceScope::adminStartDate` |
| CHG-05 | Info | A signed file link stays usable until its expiry (10 min) after the author is blocked; now embeddable cross-origin during that time | `files.show` |
| CHG-06 | Info | Three legacy assertions in `tests/security/Phase5SecurityTest.php` are stale (not regressions of these commits' security) | `tests/security/` |

### CHG-01 – Medium – admin with the attendance settings section bypasses Face ID / geofence / lateness for their own check-in
Owner rule: only a super admin manages an admin's attendance; an admin never manages themselves. The direct routes are
closed (see "sound"), but the global rules that judge the admin's own check-in remain editable by admins holding
`manage_settings_attendance`. Before 54f9133 this did not matter for the admin (admins did not check in); now it does.

Reproduction (`aSetA`: admin, only `manage_settings_attendance`, no Face ID device, not checked in, expected today):
```
PUT /api/admin/settings   (Bearer aSetA)
{"values":{"faceid_required_for_checkin":false,"late_after":"23:59","office_latitude":6.5244,"office_longitude":3.3792}}
-> 200
POST /api/attendance/check-in/intent (Bearer aSetA) {"type":"gps","latitude":6.5244,"longitude":3.3792,"accuracy":5}
-> 201 {"distance_m":0,"faceid_required":false,"faceid_options":null}
POST /api/attendance/check-in/confirm (Bearer aSetA) {"intent_id":"<id>"}
-> 201 {"check_in_distance_m":0,"is_late":false,"late_after":"23:59","faceid_verified":false}
PUT /api/admin/settings {"values":{"faceid_required_for_checkin":true,"late_after":"08:10","office_latitude":5.595223,"office_longitude":-0.216703}} -> 200
```
Observed: on-time check-in from Lagos without Face ID; the row keeps `late_after 23:59` (no lateness ever recorded).
Mitigations present: settings history, `settings.updated` log, in-app alert "Configuration changed by an admin" to
super admins, and `faceid_verified:false` on the row. Expected: an admin cannot weaken the rules that judge their own attendance.
Fix (owner decision, log it as a question): add the attendance-integrity settings to `SUPER_ADMIN_ONLY` while admins
track attendance (`faceid_required_for_checkin`, `faceid_first_device_auto_approve`, `office_latitude/longitude`,
`checkin_radius_m`, `checkin_max_accuracy_m`, `late_after`, `checkin_opens_at`, `working_days`, `auto_checkout_time`,
`end_of_day_time`), or at least flag in the super admins' views any attendance recorded while such a change by an admin was in force.

### CHG-02 – Low – self-exemption through a public holiday
```
POST /api/admin/holidays (Bearer aAll, holds manage_leaves) {"date":"2026-11-15","name":"Admin day"} -> 201
GET /api/attendance/today (Bearer aLogs, admin not checked in) -> day_status "holiday", expected false
POST /api/me/tasks (Bearer aLogs) {"title":"no checkin"} -> 201   (check-in gate skipped)
```
The holiday also removes every employee's obligations for the day, so it is conspicuous, but no super-admin alert is sent
(only the `holiday.created` log). Fix: reserve holidays on the current/past days to super admins, or alert super admins on
every holiday change made by an admin (as for settings).

### CHG-03 – Info – promotions rewrite who sees the past
`PUT /api/admin/users/3/role {"role":"admin"}` (e2, employee with an activity report and today's check-in): afterwards
`aAll` gets 404 on `GET /api/admin/reports/activity/<e2 report>`, 403 on `/admin/users/3/attendance`, and e2 disappears from
`/admin/attendance/day` – including past days. Documented in PROGRESS ("role judged as it is today"). Consistent with the
"admins never see an admin" rule; listed so the owner knows an admin loses access to an employee's history at promotion.

### CHG-04 – Info – switching the setting off is effective the same day
`PUT /api/admin/settings {"values":{"admin_attendance_starts_on":null}}` (SA) at 01:18 → `aAll` (checked in) gets
403 `employees_only` on `/attendance/today`, `/attendance/check-out`, `/me/reports/activity`, `/attendance/history`.
The day's open attendance is later closed by the automatic check-out. Matches the code comment "a value saved during a
day applies to that whole day"; suggestion: keep `/attendance/history` and `/me/reports/activity` (read-only, own data)
open to an admin who has data, and let the front end show why the employee pages vanished.

### CHG-05 – Info – signed links after block / deletion
A link obtained before `POST /admin/users/4/block` still returns 200 until `expires` (TTL `file_url_ttl_minutes`=10).
After the author deletes the report the link returns 404 (file removed) – good. With CORP `cross-origin` any site may
embed the response during the TTL, but only for someone holding the link; Content-Disposition attachment, nosniff and the
sandbox CSP make it non-renderable. Acceptable; keep the TTL short.

### CHG-06 – Info – stale legacy security tests
Run against `attendance_test`: `tests/security/Phase5SecurityTest.php` + `Phase6SecurityTest.php` → 36 passed, 2 failed, 1 error:
- "service suggestions … admin → 403": an admin with view_reports now tracks attendance (migration stamps today), so 200 is the new expected behaviour.
- "serves files only through valid … signed URLs": the upload of a PDF containing `<html><script>` is now refused (`pdf_active_content`), so `file_url` is null – the test needs a clean PDF.
- "monthly PDF escapes HTML": the fixture client dated 2026-11-26 is outside the current monthly period (26→25 since the 2026-11-13 period change).
None indicates a vulnerability; update the tests (attendance-backend).

## Tested and sound
Admin attendance (54f9133), as `aAll` (every permission, admin):
- 403/404 on every single-target route with an admin target or themselves: `/admin/users/{admin|self}/attendance`, `/check-in`
  (self, another admin), `/work-schedule` GET/PUT, `/leaves` POST, `DELETE /admin/leaves/{admin leave}`, mission approve/reject of an
  admin's mission, Face ID approve/reject/revoke of an admin's or own credential, `/admin/users/{admin|self}/reports/weekly`,
  `/admin/reports/weekly/submissions/{admin}`, `/admin/reports/activity/{admin|own}`; user management of admins (show, role,
  permissions, block, delete, code) 403.
- No admin row, name, title or e-mail in 27 list variants (day ±date, missions, attempts ±`user_id`, leaves ±`user_id`/range, daily
  ±`user_id` admin/self, weekly, activity ±`user_id`/range/`per_page=100`, Face ID pending/approved ±`user_id`, dashboard). Dashboard
  totals for the admin cover employees only (expected 3 vs 11 for the SA); `pending.faceid_devices`/`missions` exclude admins.
- Activity log as a `view_logs` admin: no `attendance.|faceid.|schedule.|leave.|reports.` entry about an admin in 10 filter variants
  (`user_id`, `actor_id`, `event`, `per_page=200`).
- Alerts about admins (mission, new device, working week, late weekly report, activity report) reached super admins only.
- `admin_attendance_starts_on`: refused to admins (alone, mixed with `late_after`, for `aSetA`, duplicate JSON keys, array value,
  form-encoded), hidden from their GET and history. Invalid values (`2026-13-40`, `tomorrow`, number, array, `0000-00-00`) → 422.
- No retroactivity: with the date moved back to 2026-11-01 the SA's day view for 2026-11-10/13 still lists employees only and the
  weekly window of 2026-11-13 expects nobody extra (values judged as in force on each day). Future date → admins 403 `employees_only`,
  `/me` shows `tracks_attendance:false` and the date. Null → SA on-behalf check-in of an admin → 422 `attendance_not_tracked`.
- Check-in gate: admin not checked in → 403 `checkin_required` on tasks and activity upload; admin pages open (Q30 as designed).
  Super admin → 403 `employees_only` on every employee route. Mass assignment (`role`, `status`, `permissions`, `tracks_attendance`
  in `PATCH /me`; `user_id`, `work_date` in tasks) ignored. Promotion revokes the promoted user's tokens (401); promoted super admin
  loses the employee routes.
Activity reports (d521417):
- Upload checks: PHP/HTML polyglots, `/JavaScript`, `/J#61vaScript`, `/Launch`, `/EmbeddedFile` → `pdf_active_content`; `/Encrypt`
  → `pdf_encrypted`; MZ/leading-whitespace files → `invalid_pdf`; 11 MB → 413; array of files, no file, JSON body → 422; 100 kB
  field name ignored. File name `../../../.env.pdf` stored as display text `.env.pdf`, path server-generated.
- Title: links, obfuscated links, e-mail addresses, CRLF header attempt, HTML → 422; control/bidi characters → 422
  `invalid_characters`; 151 chars → 422; 150 emoji accepted (counted as characters); whitespace/zero-width only → 422; note 1001 → 422.
- Daily limit: 12 parallel uploads with limit 3 → exactly 3×201, 9×422; a deletion does not give the slot back; rejected uploads
  leave no file on disk. Delete window 0 → 422 `delete_window_closed`; other user's or admin's delete → 404; double delete → 404.
- Readers: employee and admin without tier → 403; `aRecv` reads an employee report, 404 on an admin's; unknown/deleted id → 404;
  blocked author → 401 `account_blocked`.
File links (1110430): `files.show` → CORP `cross-origin`, `Content-Disposition: attachment; filename="report.pdf"`, nosniff,
`sandbox` CSP; CORS stays `http://localhost:5173` even with `Origin: https://evil.example`. Tampered signature, path swapped
under the same signature, `..` and `%2e%2e` traversal, bumped `expires` → 404. Unsigned file URL, `/health`, `/me`, admin routes keep
CORP `same-site`.
Regression: full Pest suite 644 passed (4370 assertions).
Load (local, 12 PHP workers): 100 parallel `GET /admin/reports/activity?per_page=100` → 100×200, p50 522 / p95 914 / p99 979 ms;
100 parallel own lists → p50 470 / p95 883 / p99 987 ms; 30 parallel uploads → 20×201 (10×401 from a token revoked by the
block test), p95 312 ms; 60 parallel admin dashboards → p50 398 / p95 704 ms. No 5xx.

## Not tested and why
- Scheduled jobs with admins (weekly close e-mail, monthly PDF per reader, check-in reminder) over HTTP: covered by
  `AdminsTrackAttendanceTest.php` (passing); not re-run under a frozen clock.
- Date boundary of the daily limit at midnight Africa/Accra with a moved system clock: no clock faking available in the container
  (server time used; the work date is server-side, no client date accepted).
- S3/Supabase pre-signed URLs (production disk): out of scope (no production / Supabase access allowed).
- Activity-report e-mail (`activity_report_email` on): not enabled; titles that reach the mail are already constrained (see above).
