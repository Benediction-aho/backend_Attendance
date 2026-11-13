#!/usr/bin/env bash
# Release audit fixtures (LOCAL ONLY). Creates users through the API and writes tokens to $OUT.
# Usage: B=http://localhost:8282/api OUT=dir bash tests/security/release_fixtures.sh
source "$(dirname "$0")/lib.sh"
OUT=${OUT:?}
SA=$(cat "$OUT/sa")
mk() { # email role perms_json -> token
  local e=$1 role=$2 perms=$3
  api POST /admin/users "$SA" "{\"email\":\"$e\",\"first_name\":\"F\",\"last_name\":\"L\",\"employee_type\":\"employee\",\"job_title\":\"QA\",\"role\":\"$role\",\"permissions\":$perms}" >/dev/null
  local c; c=$(idcode_of "$e")
  local r; r=$(api POST /auth/login '' "{\"email\":\"$e\",\"password\":\"$c\",\"device_id\":\"$(dev)\"}")
  echo "$r" | sed 's/^[0-9]* //' | json token
}
for i in 1 2 3; do mk "e$i@relsec.test" employee '[]' > "$OUT/e$i"; done
mk "a0@relsec.test" admin '[]' > "$OUT/a0"
for p in view_performance view_lateness view_clients view_tasks approve_mission_checkins approve_faceid_devices checkin_for_employee manage_leaves manage_work_schedules create_users approve_users block_users delete_users view_reports view_activities view_statistics receive_weekly_reports promote_to_super_admin view_logs manage_settings_attendance manage_settings_reports manage_settings_security; do
  mk "a_$p@relsec.test" admin "[\"$p\"]" > "$OUT/a_$p"
done
wc -c "$OUT"/* | head -40
