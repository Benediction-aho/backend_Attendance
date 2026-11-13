#!/usr/bin/env bash
# Release audit: status code of every admin GET route (and refusal-probing writes with empty bodies)
# for every role/tier token in $OUT. Output: route x caller table. LOCAL ONLY.
source "$(dirname "$0")/lib.sh"
OUT=${OUT:?}; EMP=${EMP_ID:-4}; ADM=${ADM_ID:-6}; SAID=${SA_ID:-1}
routes=(
 "GET /admin/users" "GET /admin/users/$EMP" "GET /admin/users/$ADM" "GET /admin/users/$SAID"
 "GET /admin/permissions" "GET /admin/settings" "GET /admin/settings/history"
 "GET /admin/activity-logs" "GET /admin/activity-logs/export" "GET /admin/dashboard"
 "GET /admin/attendance/day" "GET /admin/attendance/missions" "GET /admin/attendance/attempts"
 "GET /admin/users/$EMP/attendance" "GET /admin/users/$EMP/work-schedule" "GET /admin/leaves"
 "GET /admin/reports/daily" "GET /admin/reports/weekly" "GET /admin/users/$EMP/reports/weekly"
 "GET /admin/faceid/credentials"
 "POST /admin/users/$EMP/check-in" "PUT /admin/users/$EMP/work-schedule" "POST /admin/users/$EMP/leaves"
 "POST /admin/holidays" "POST /admin/users/$EMP/unlock" "POST /admin/users/$EMP/code"
 "PUT /admin/users/$EMP/permissions" "PUT /admin/users/$EMP/role" "POST /admin/users"
 "PUT /admin/settings" "POST /admin/attendance/999999/mission/approve" "POST /admin/faceid/credentials/999999/approve"
 "POST /admin/faceid/credentials/999999/revoke" "DELETE /admin/leaves/999999" "DELETE /admin/holidays/999999"
)
callers=(e1 a0 $(cd "$OUT" && ls a_* ) sa)
printf "%-48s" route; for c in "${callers[@]}"; do printf "%s;" "$c"; done; echo
for r in "${routes[@]}"; do
  m=${r%% *}; p=${r#* }
  printf "%-48s" "$r"
  for c in "${callers[@]}"; do
    t=$(cat "$OUT/$c")
    if [ "$m" = GET ]; then s=$(api GET "$p" "$t" | cut -d' ' -f1); else s=$(api "$m" "$p" "$t" '{"__probe":1}' | cut -d' ' -f1); fi
    printf "%s;" "$s"
  done; echo
done
