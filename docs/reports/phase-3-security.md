# Phase 3 – Manual attendance – Security report

Tester: `backend-security-tester` – 2026-11-12 – branch `claude/upbeat-noether-f7defe` (commit e76296f).
Environment: local only, `APP_ENV=production APP_DEBUG=false QUEUE_CONNECTION=sync MAIL_MAILER=log PHP_CLI_SERVER_WORKERS=16 php artisan serve --port=8181`, PostgreSQL local. No real email sent (log mailer, `*.invalid` addresses).

## How to reproduce

```bash
php artisan attendance:load-fixtures --count=230           # employees + tokens (refused in production)
php tests/security/phase3_fixtures.php > /tmp/p3.json        # SA + admins with given permission sets
P3=/tmp/p3.json tests/security/phase3_attendance.sh          # HTTP checks (75 assertions)
php tests/security/phase3_clock.php                          # frozen-clock checks, rolled back (20 assertions)
bash tests/load/parallel_checkin.sh http://localhost:8181/api <first-200-tokens.json>
```

## Findings

### P3-01 – Employee can self-declare every working day as remote, effective immediately – Medium
- Endpoint: `POST /api/me/work-schedule` (`WorkScheduleController::validated`, `storeMine`).
- Steps: employee with no schedule yet sends `{"days_off":[],"remote_days":[1,2,3,4,5]}` → 201; the same minute `POST /attendance/check-in/intent {"type":"remote"}` → 201 (check `self-select-all-remote` and `remote-same-day-after-self-select` in `phase3_attendance.sh`).
- Observed: `remote_days` is only bounded by `max:7` and the working days; the schedule is effective today. One request removes every future GPS/perimeter check for this employee until an admin notices; no admin is notified.
- Expected (SPECS 6 bis): days are "fixed by the contract"; the GPS perimeter is the core control. The one-time self-selection should not be able to disable it entirely.
- Fix: add a `max_remote_days_per_week` setting (safe default, e.g. 1 or 2) validated like `max_days_off_per_week`; make an employee selection effective from the next work date (or require an admin with `manage_work_schedules` to validate selections that contain remote days); notify `manage_work_schedules` holders of every self-selection. Log as a business question in PROGRESS.md (number of remote days allowed).

### P3-02 – Check-in reminder: a failed run re-sends to everyone, a crashed run is never caught up – Low
- File: `app/Services/ScheduledRunner.php` + `app/Console/Commands/CheckinReminder.php` (code review).
- Observed: the reminder loops over employees with `notifyNow` (mail + database) inside one `once()` closure. If an exception happens after N employees (SMTP error on one address), the run is marked `failed` and the next tick re-runs the whole loop → the N first employees get a duplicate email/notification (FIABILITE B6 "no duplicate"). If the process dies (OOM, kill, deploy) while the row is `running`, `once()` returns false forever for that key → no catch-up that day.
- Fix: record per-recipient delivery (e.g. unique `(run_key, user_id)` row or check the existing `notifications` row for the work date) and skip already-notified employees on retry; catch per-recipient exceptions and continue; treat a `running` row older than a timeout (e.g. 10 min) as failed and reclaim it.

### P3-03 – Intent not bound to the device that created it – Low
- Endpoint: `POST /attendance/check-in/confirm` (`AttendanceService::confirm`).
- Observed: `checkin_intents.device_id` is stored but not compared with the device of the confirming token; another device session of the same user can confirm the intent created by the first.
- Expected: the position check and the Face ID confirmation should come from the same device (prerequisite for phase 4: the WebAuthn challenge must be bound to intent + device + credential of that device).
- Fix: in the claim `UPDATE`, add `where device_id = current token device` (or reject with `intent_invalid`); in phase 4 bind the challenge to the intent row and consume both atomically.

### P3-04 – Face ID not enforced at confirmation (expected until phase 4) – Info
- `faceid_required_for_checkin` defaults to `false`; `confirm` without `assertion` creates the attendance. SPECS 6 requires Face ID for gps, mission and remote check-ins. Must be switched on (and the default changed to `true`) when phase 4 ships; re-test then (assertion replay, foreign credential, revoked credential).

### P3-05 – Position and accuracy are client-declared – Info
- `accuracy: 0` with the office coordinates is accepted (`acc-zero-accepted`). Inherent to browser geolocation (mock-location apps). Mitigations already present: server-side distance, refused attempts stored (`checkin_attempts`), rate limit. Phase 4 Face ID is the real control; consider flagging attempts patterns (many refusals then exact office coordinates with accuracy < 1 m) in the admin view.

### P3-06 – `checked-in` middleware not yet applied to any route – Info
- The alias exists (`bootstrap/app.php`) and the logic is correct, but no route uses it yet (tasks/clients/reports come in phase 5). Re-test in phase 5 that every employee action route carries it.

### P3-07 – Free-text fields stored raw – Info
- Mission reason `<img src=x onerror=alert(1)>` is stored and returned as-is in JSON (correct for an API). The frontend and the email/notification templates must escape it (the StaffAlert body includes the reason).

## Summary

| ID | Title | Severity | Status |
|---|---|---|---|
| P3-01 | Self-selected remote days unbounded, effective immediately | Medium | Fixed (to re-test) |
| P3-02 | Reminder retry duplicates / stuck `running` run | Low | Fixed (to re-test) |
| P3-03 | Intent not bound to device | Low | Fixed (to re-test) |
| P3-04 | Face ID not enforced yet (phase 4) | Info | Open (phase 4) |
| P3-05 | Client-declared accuracy | Info | Accepted risk |
| P3-06 | `checked-in` middleware unused yet | Info | Re-test phase 5 |
| P3-07 | Raw free text must be escaped by clients | Info | Backend emails verified; frontend to check |

No Critical or High finding.

## Tests that passed

Intent / confirm
- Confirm with random UUID, non-UUID (`1 OR 1=1`), another user's intent → 422 `intent_invalid`.
- Expired intent, intent whose work date is not today, intent created 23:59:30 and confirmed 00:00:10 → refused.
- Replay of a consumed intent → 200 `created:false` (idempotent, no second row); new intent after check-in → 409.
- Client-sent `check_in_at`, `is_late`, `work_date`, `user_id` in confirm / on-behalf / check-out (`check_out_at`, `early_leave`) are ignored; server time used.
- Invalid JSON → 422 without stack trace.

Position
- lat 91, array values, accuracy `1e400`, negative, missing, 51 m (> 50), far-away and hex/odd numeric strings, `type: admin` → 422. Refused attempts recorded in `checkin_attempts`; raw coordinates of attempts not returned to non-super admins.

Clock boundaries (frozen clock, `phase3_clock.php`)
- 08:09:59.999 not late; 08:10:00 late; intent 08:09:50 / confirm 08:10:05 → late (confirmation time, Q16).
- 23:59:59 check-in has work date of that day.
- Check-out 16:59:59 requires a reason; 17:00:00 does not; blank reason refused; second check-out 409; check-out before check-in 409.

Eligibility and authorization
- Remote check-in on a non-remote day → 422; second schedule self-selection → 409; 3 days off / Sunday → 422.
- Mission without / blank reason → 422; approval by employee or admin without permission → 403; second decision → 409; approve on a non-mission attendance → 404.
- On-behalf check-in: employee token, admin without permission, admin with other permissions → 403; target admin / super admin → 403; no reason → 422; success logged (`attendance.admin_check_in`, warning, actor + reason); second → 409.
- Schedules: update by employee / admin without permission → 403; past `effective_from` → 422. Leaves: employee / admin without permission → 403, leave for super admin → 403, reversed dates → 422. Holidays: employee / unauthorized admins → 403.
- GPS coordinates only returned to super admin (`check_in_position`); `is_late` / `early_leave_reason` hidden from admins without `view_lateness`.
- IDOR: `/attendance/history?user_id=` ignored (own data only); admin history of a super admin → 403; admin views as employee / admin without permission → 403; admin calling employee attendance routes → 403.
- Rate limit: 25 rapid intents → 20 processed, then 429 (`checkin_attempts_per_minute` = 20, shared with check-out).

Scheduled jobs (frozen clock)
- Auto check-out: no-op before midnight; missed midnight run caught up at 09:30 with `check_out_at` = 00:00 and `auto_midnight`; second run changes nothing, one `scheduled_runs` row; manual check-outs not overwritten; check-out after auto → refused.
- Reminder: nothing before 08:05 nor at/after 17:00; missed run caught up at 11:00; run three times → sent once; not sent to checked-in, day-off, on-leave employees; sent to expected ones.

Concurrency
- `tests/load/parallel_checkin.sh`, 200 employees in parallel, each intent + 2 simultaneous confirms: 200 × intent 201, 200 × confirm 201, 200 × confirm 200 (idempotent); 0 errors, 0 duplicates (`GROUP BY user_id, work_date HAVING count > 1` = 0). Wall time 23 s on `php artisan serve` with 16 workers (not representative of production latency; k6 p50/p95/p99 to be measured on the test instance).

Logs
- `storage/logs/laravel.log` contains no bearer token, intent id or coordinates after the run; server log has no errors.

## Fixes applied (attendance-backend, 2026-11-12)

Regression tests: `tests/Feature/Attendance/Phase3SecurityFixesTest.php` (+ updated `AdminAttendanceTest`).

- **P3-01**: setting `max_remote_days_per_week` (default 2, 0–7; owner question Q18) validated like `max_days_off_per_week` (`remote_days.max`). `POST /me/work-schedule` now stores `effective_from` = next day: the same-day remote check-in is refused (`not_remote_day`). Every `manage_work_schedules` holder receives a `schedule_selected` StaffAlert (database + email, queued). Days off still capped by `max_days_off_per_week`.
- **P3-02**: new table `reminder_deliveries` (unique `kind, user_id, work_date`, reversible migration). The reminder claims each recipient with `INSERT … ON CONFLICT DO NOTHING` before sending; a send exception releases that claim, is reported, and the loop continues; the run is then marked failed so the next tick retries only the remaining recipients. `ScheduledRunner::once` now also reclaims (atomic conditional UPDATE) a `running` row older than the new setting `scheduled_run_stale_minutes` (default 15, 1–1440) – applies to all jobs (reminder, auto check-out, log purge).
- **P3-03**: `AttendanceService::confirm` compares the intent's `device_id` with the device of the confirming token; mismatch → 422 `intent_invalid`, nothing written.
- **P3-07**: email templates (`resources/views/emails/simple*.blade.php`) only use `{{ }}`; tests render `StaffAlertMail` from a mission check-in with `<img src=x onerror=…><script>` and with HTML in title/body and assert the output is escaped. In-app notification JSON keeps raw text (frontend must render it as text).

Checks: Pest 275 passed, Larastan 0 errors, Pint clean, rollback/migrate OK.
