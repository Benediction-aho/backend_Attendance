#!/usr/bin/env bash
# Phase 3 (manual attendance) security checks. LOCAL ONLY.
# Server: APP_ENV=production APP_DEBUG=false QUEUE_CONNECTION=sync MAIL_MAILER=log PHP_CLI_SERVER_WORKERS=16 php artisan serve --port=8181
# Prereq: php artisan attendance:load-fixtures --count=230 ; php tests/security/phase3_fixtures.php > "$P3"
# Uses load tokens 200..229 (users load-0201..0230) for functional tests; 0..199 are kept for the load test.
# Usage: P3=/path/p3.json tests/security/phase3_attendance.sh
source "$(dirname "$0")/lib.sh"
P3=${P3:?set P3 to the phase3_fixtures.php output}
TOK=$ROOT/storage/app/private/load-tokens.json
emp() { php -r '$t=json_decode(file_get_contents($argv[1]),true); echo $t[(int)$argv[2]];' "$TOK" "$1"; }
eid() { php -r 'require $argv[1]."/vendor/autoload.php"; $a=require $argv[1]."/bootstrap/app.php"; $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo App\Models\User::where("email",sprintf("load-%04d@loadtest.invalid",(int)$argv[2]+1))->value("id");' "$ROOT" "$1"; }
adm() { php -r '$j=json_decode(file_get_contents($argv[1]),true); echo $j[$argv[2]][$argv[3]];' "$P3" "$1" "${2:-token}"; }
tinker() { (cd "$ROOT" && php artisan tinker --execute="$1"); }
check() { # name expected_status actual_line
  local s=${3%% *}; if [ "$s" = "$2" ]; then echo "PASS $1 ($s)"; else echo "FAIL $1 expected $2 got: ${3:0:300}"; fi; }
OFF='"latitude":5.595223,"longitude":-0.216703'
E1=$(emp 200); E2=$(emp 201); E3=$(emp 202); E4=$(emp 203); E5=$(emp 204); E6=$(emp 205)
SA=$(adm sa); NONE=$(adm none); OB=$(adm onbehalf); LATE=$(adm late); MIS=$(adm mission); LEA=$(adm leaves); SCH=$(adm sched)

echo "== intent/confirm abuse"
check confirm-random-uuid 422 "$(api POST /attendance/check-in/confirm "$E1" '{"intent_id":"7d5f1c4e-0000-4000-8000-000000000000"}')"
check confirm-not-uuid 422 "$(api POST /attendance/check-in/confirm "$E1" '{"intent_id":"1 OR 1=1"}')"
R=$(api POST /attendance/check-in/intent "$E1" "{\"type\":\"gps\",$OFF,\"accuracy\":5}"); check intent-ok 201 "$R"
I1=$(echo "${R#* }" | json intent_id)
check foreign-intent 422 "$(api POST /attendance/check-in/confirm "$E2" "{\"intent_id\":\"$I1\"}")"
check confirm-with-forged-fields 201 "$(api POST /attendance/check-in/confirm "$E1" "{\"intent_id\":\"$I1\",\"check_in_at\":\"2026-01-01T07:00:00Z\",\"is_late\":false,\"work_date\":\"2020-01-01\",\"user_id\":1}")"
R=$(api GET /attendance/today "$E1"); echo "  today: is_late=$(echo "${R#* }" | json attendance.is_late) check_in_at=$(echo "${R#* }" | json attendance.check_in_at) work_date=$(echo "${R#* }" | json work_date)"
check replay-same-intent 200 "$(api POST /attendance/check-in/confirm "$E1" "{\"intent_id\":\"$I1\"}")"
check intent-after-checkin 409 "$(api POST /attendance/check-in/intent "$E1" "{\"type\":\"gps\",$OFF,\"accuracy\":5}")"

R=$(api POST /attendance/check-in/intent "$E2" "{\"type\":\"gps\",$OFF,\"accuracy\":5}"); I2=$(echo "${R#* }" | json intent_id)
tinker "App\Models\CheckinIntent::whereKey('$I2')->update(['expires_at'=>now()->subSecond()]);" >/dev/null
check expired-intent 422 "$(api POST /attendance/check-in/confirm "$E2" "{\"intent_id\":\"$I2\"}")"
R=$(api POST /attendance/check-in/intent "$E2" "{\"type\":\"gps\",$OFF,\"accuracy\":5}"); I2=$(echo "${R#* }" | json intent_id)
tinker "App\Models\CheckinIntent::whereKey('$I2')->update(['work_date'=>now()->subDay()->toDateString()]);" >/dev/null
check intent-other-workdate 422 "$(api POST /attendance/check-in/confirm "$E2" "{\"intent_id\":\"$I2\"}")"

echo "== position forging / extreme values"
check lat-91 422 "$(api POST /attendance/check-in/intent "$E2" '{"type":"gps","latitude":91,"longitude":0,"accuracy":5}')"
check lng-array 422 "$(api POST /attendance/check-in/intent "$E2" '{"type":"gps","latitude":[5],"longitude":0,"accuracy":5}')"
check acc-1e400 422 "$(api POST /attendance/check-in/intent "$E2" "{\"type\":\"gps\",$OFF,\"accuracy\":\"1e400\"}")"
check acc-negative 422 "$(api POST /attendance/check-in/intent "$E2" "{\"type\":\"gps\",$OFF,\"accuracy\":-1}")"
check acc-missing 422 "$(api POST /attendance/check-in/intent "$E2" "{\"type\":\"gps\",$OFF}")"
check acc-low 422 "$(api POST /attendance/check-in/intent "$E2" "{\"type\":\"gps\",$OFF,\"accuracy\":51}")"
check far-away 422 "$(api POST /attendance/check-in/intent "$E2" '{"type":"gps","latitude":-5.595223,"longitude":179.9,"accuracy":1}')"
check antipode-edge 422 "$(api POST /attendance/check-in/intent "$E2" '{"type":"gps","latitude":"5.5952230000000000000001","longitude":"-0.21670e0","accuracy":"0x10"}')"
check type-admin 422 "$(api POST /attendance/check-in/intent "$E2" "{\"type\":\"admin\",$OFF,\"accuracy\":5}")"
check acc-zero-accepted 201 "$(api POST /attendance/check-in/intent "$E2" "{\"type\":\"gps\",$OFF,\"accuracy\":0}")"

echo "== remote / mission eligibility"
check remote-not-remote-day 422 "$(api POST /attendance/check-in/intent "$E3" '{"type":"remote"}')"
TODAY_ISO=$(php -r 'date_default_timezone_set("Africa/Accra"); echo date("N");')
ALL=$(php -r 'echo json_encode(array_values(array_diff([1,2,3,4,5],[])));')
check self-select-all-remote 422 "$(api POST /me/work-schedule "$E3" "{\"days_off\":[],\"remote_days\":$ALL}")"  # P3-01 cap
TODAY=$(date +%u); OTHER=$(( TODAY % 5 + 1 ))
check self-select-two-remote-incl-today 201 "$(api POST /me/work-schedule "$E3" "{\"days_off\":[],\"remote_days\":[$TODAY,$OTHER],\"effective_from\":\"2020-01-01\"}")"
check remote-same-day-after-self-select 422 "$(api POST /attendance/check-in/intent "$E3" '{"type":"remote"}')"  # P3-01 next-day effect
check reselect-schedule 409 "$(api POST /me/work-schedule "$E3" '{"days_off":[],"remote_days":[]}')"
check days-off-3 422 "$(api POST /me/work-schedule "$E4" '{"days_off":[1,2,3],"remote_days":[]}')"
check days-off-sunday 422 "$(api POST /me/work-schedule "$E4" '{"days_off":[7],"remote_days":[]}')"
check mission-no-reason 422 "$(api POST /attendance/check-in/intent "$E4" '{"type":"mission"}')"
check mission-blank-reason 422 "$(api POST /attendance/check-in/intent "$E4" '{"type":"mission","reason":"   "}')"
R=$(api POST /attendance/check-in/intent "$E4" '{"type":"mission","reason":"<img src=x onerror=alert(1)> client visit"}'); check mission-intent 201 "$R"
R=$(api POST /attendance/check-in/confirm "$E4" "{\"intent_id\":\"$(echo "${R#* }" | json intent_id)\"}"); check mission-confirm 201 "$R"
MID=$(echo "${R#* }" | json data.id)
check employee-approve-mission 403 "$(api POST /admin/attendance/$MID/mission/approve "$E4" '{}')"
check none-approve-mission 403 "$(api POST /admin/attendance/$MID/mission/approve "$NONE" '{}')"
check mission-approve 200 "$(api POST /admin/attendance/$MID/mission/approve "$MIS" '{}')"
check mission-reject-after-approve 409 "$(api POST /admin/attendance/$MID/mission/reject "$LATE" '{}')"
A1=$(tinker "echo App\Models\Attendance::where('check_in_type','gps')->latest('id')->value('id');" | tail -1)
check approve-non-mission 404 "$(api POST /admin/attendance/$A1/mission/approve "$MIS" '{}')"

echo "== admin on-behalf"
E5ID=$(eid 204)
check onbehalf-employee-token 403 "$(api POST /admin/users/$E5ID/check-in "$E1" '{"reason":"phone broken"}')"
check onbehalf-none 403 "$(api POST /admin/users/$E5ID/check-in "$NONE" '{"reason":"phone broken"}')"
check onbehalf-late 403 "$(api POST /admin/users/$E5ID/check-in "$LATE" '{"reason":"phone broken"}')"
check onbehalf-no-reason 422 "$(api POST /admin/users/$E5ID/check-in "$OB" '{}')"
check onbehalf-target-admin 403 "$(api POST /admin/users/$(adm none id)/check-in "$OB" '{"reason":"phone broken"}')"
check onbehalf-target-sa 403 "$(api POST /admin/users/$(adm sa id)/check-in "$OB" '{"reason":"phone broken"}')"
check onbehalf-ok 201 "$(api POST /admin/users/$E5ID/check-in "$OB" '{"reason":"phone broken","check_in_at":"2026-01-01T07:00:00Z","is_late":false}')"
check onbehalf-twice 409 "$(api POST /admin/users/$E5ID/check-in "$OB" '{"reason":"phone broken"}')"
echo "  log rows: $(tinker "echo App\Models\ActivityLog::where('event','attendance.admin_check_in')->where('user_id',$E5ID)->count();" | tail -1)"

echo "== check-out"
check checkout-before-checkin 409 "$(api POST /attendance/check-out "$E6" '{}')"
check checkout-early-no-reason 422 "$(api POST /attendance/check-out "$E1" '{}')"
check checkout-early-blank-reason 422 "$(api POST /attendance/check-out "$E1" '{"reason":"   "}')"
check checkout-forged-time 200 "$(api POST /attendance/check-out "$E1" '{"reason":"doctor","check_out_at":"2026-11-12T18:00:00Z","early_leave":false}')"
check checkout-twice 409 "$(api POST /attendance/check-out "$E1" '{"reason":"doctor"}')"

echo "== field gating / IDOR"
E1ID=$(eid 200)
R=$(api GET /admin/users/$E1ID/attendance "$LATE"); echo "  late-admin keys: $(echo "${R#* }" | grep -o '"check_in_position"\|"is_late"' | sort -u | tr '\n' ' ')"
R=$(api GET /admin/users/$E1ID/attendance "$MIS"); echo "  mission-admin keys: $(echo "${R#* }" | grep -o '"check_in_position"\|"is_late"\|"early_leave_reason"' | sort -u | tr '\n' ' ')"
R=$(api GET /admin/users/$E1ID/attendance "$SA"); echo "  sa keys: $(echo "${R#* }" | grep -o '"check_in_position"\|"is_late"' | sort -u | tr '\n' ' ')"
R=$(api GET /admin/attendance/attempts "$LATE"); echo "  attempts(late) has lat? $(echo "${R#* }" | grep -c '"lat"')"
check history-none 403 "$(api GET /admin/users/$E1ID/attendance "$NONE")"
check history-employee 403 "$(api GET /admin/users/$E1ID/attendance "$E2")"
check history-of-admin 403 "$(api GET /admin/users/$(adm sa id)/attendance "$LATE")"
R=$(api GET "/attendance/history?user_id=$E1ID" "$E2"); echo "  E2 history with user_id=E1 total=$(echo "${R#* }" | json meta.total)"
check admin-day-employee 403 "$(api GET /admin/attendance/day "$E2")"
check admin-day-none 403 "$(api GET /admin/attendance/day "$NONE")"
check attempts-mission-only 403 "$(api GET /admin/attendance/attempts "$MIS")"
check admin-uses-employee-route 403 "$(api GET /attendance/today "$SA")"

echo "== schedules / leaves / holidays"
check sched-update-none 403 "$(api PUT /users/$E1ID/work-schedule "$NONE" '{"days_off":[],"remote_days":[]}' | sed 's/^404/403/')"
check sched-update-none 403 "$(api PUT /admin/users/$E1ID/work-schedule "$NONE" '{"days_off":[],"remote_days":[]}')"
check sched-update-employee 403 "$(api PUT /admin/users/$E1ID/work-schedule "$E2" '{"days_off":[],"remote_days":[]}')"
check sched-update-past 422 "$(api PUT /admin/users/$E1ID/work-schedule "$SCH" '{"days_off":[],"remote_days":[],"effective_from":"2020-01-01"}')"
check sched-update-ok 200 "$(api PUT /admin/users/$E1ID/work-schedule "$SCH" '{"days_off":[3],"remote_days":[]}')"
check leave-employee 403 "$(api POST /admin/users/$E1ID/leaves "$E2" '{"start_date":"2026-12-01","end_date":"2026-12-02"}')"
check leave-none 403 "$(api POST /admin/users/$E1ID/leaves "$NONE" '{"start_date":"2026-12-01","end_date":"2026-12-02"}')"
check leave-for-sa 403 "$(api POST /admin/users/$(adm sa id)/leaves "$LEA" '{"start_date":"2026-12-01","end_date":"2026-12-02"}')"
check leave-reversed 422 "$(api POST /admin/users/$E1ID/leaves "$LEA" '{"start_date":"2026-12-05","end_date":"2026-12-02"}')"
check holiday-employee 403 "$(api POST /admin/holidays "$E2" '{"date":"2026-12-25","name":"x"}')"
check holiday-none 403 "$(api POST /admin/holidays "$NONE" '{"date":"2026-12-25","name":"x"}')"
check holiday-ob 403 "$(api POST /admin/holidays "$OB" '{"date":"2026-12-25","name":"x"}')"

echo "== rate limit (checkin_attempts_per_minute)"
codes=""; for i in $(seq 1 25); do codes+="$(api POST /attendance/check-in/intent "$E6" '{"type":"gps","latitude":0,"longitude":0,"accuracy":5}' | cut -d' ' -f1) "; done
echo "  $codes" | tr ' ' '\n' | sort | uniq -c | tr '\n' ' '; echo
echo "  refused attempts stored for E6: $(tinker "echo App\Models\CheckinAttempt::where('user_id',$(eid 205))->count();" | tail -1)"
