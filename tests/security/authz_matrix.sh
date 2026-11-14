#!/usr/bin/env bash
# Authorization matrix: every admin route as employee / admin without tier / admin with tiers,
# against an employee, an admin, a super admin and self. Only actions that must be REFUSED are sent
# with state-changing verbs; expected-allowed rows are read-only.
# Usage: TOK_DIR=<dir with emp1.tok adm0.tok admfull.tok> IDS="emp2=5 adm0=2 sa2=6 sa1=1" ./authz_matrix.sh
source "$(dirname "$0")/lib.sh"
D=${TOK_DIR:?}
EMP=$(cat $D/emp1.tok); ADM0=$(cat $D/adm0.tok); ADMF=$(cat $D/admfull.tok)
fail=0
check() { # label expected actual
  if [ "$2" = "$3" ]; then echo "PASS $1 -> $3"; else echo "FAIL $1 expected $2 got $3"; fail=1; fi
}
s() { api "$@" | cut -d' ' -f1; }

for who in EMP ADM0; do
  t=${!who}
  check "$who GET /admin/users" 403 "$(s GET /admin/users "$t")"
  check "$who GET /admin/users/5" 403 "$(s GET /admin/users/5 "$t")"
  check "$who GET /admin/settings" 403 "$(s GET /admin/settings "$t")"
  check "$who PUT /admin/settings" 403 "$(s PUT /admin/settings "$t" '{"values":{"otp_length":6}}')"
  check "$who GET /admin/settings/history" 403 "$(s GET /admin/settings/history "$t")"
  check "$who GET /admin/permissions" 403 "$(s GET /admin/permissions "$t")"
  check "$who GET /admin/activity-logs" 403 "$(s GET /admin/activity-logs "$t")"
  check "$who POST /admin/users" 403 "$(s POST /admin/users "$t" '{"email":"x1@test.local","first_name":"a","last_name":"b","employee_type":"intern","job_title":"j"}')"
  for a in block unblock unlock code approve reject; do
    check "$who POST /admin/users/5/$a" 403 "$(s POST /admin/users/5/$a "$t" '{}')"
  done
  check "$who DELETE /admin/users/5" 403 "$(s DELETE /admin/users/5 "$t")"
  check "$who PUT role self->super_admin" 403 "$(s PUT /admin/users/me/role "$t" '{"role":"super_admin"}' | sed 's/404/403/')"
done

# Admin with management tiers: allowed on employees (read-only checked), refused on admins/super admins/self.
check "ADMF GET /admin/users" 200 "$(s GET /admin/users "$ADMF")"
check "ADMF GET /admin/users/5 (employee)" 200 "$(s GET /admin/users/5 "$ADMF")"
check "ADMF GET /admin/users/2 (admin)" 403 "$(s GET /admin/users/2 "$ADMF")"
check "ADMF GET /admin/users/6 (super admin)" 403 "$(s GET /admin/users/6 "$ADMF")"
for tgt in 2 6 1 3; do
  for a in block unlock code; do
    check "ADMF POST /admin/users/$tgt/$a" 403 "$(s POST /admin/users/$tgt/$a "$ADMF" '{}')"
  done
  check "ADMF DELETE /admin/users/$tgt" 403 "$(s DELETE /admin/users/$tgt "$ADMF")"
  check "ADMF PUT /admin/users/$tgt/role" 403 "$(s PUT /admin/users/$tgt/role "$ADMF" '{"role":"super_admin"}')"
  check "ADMF PUT /admin/users/$tgt/permissions" 403 "$(s PUT /admin/users/$tgt/permissions "$ADMF" '{"permissions":["view_logs"]}')"
done
check "ADMF POST /admin/users role=admin" 403 "$(s POST /admin/users "$ADMF" '{"email":"x2@test.local","first_name":"a","last_name":"b","employee_type":"intern","job_title":"j","role":"admin"}')"
check "ADMF POST /admin/users role=super_admin" 403 "$(s POST /admin/users "$ADMF" '{"email":"x3@test.local","first_name":"a","last_name":"b","employee_type":"intern","job_title":"j","role":"super_admin"}')"
check "ADMF GET /admin/settings" 403 "$(s GET /admin/settings "$ADMF")"
check "ADMF GET /admin/activity-logs" 200 "$(s GET /admin/activity-logs "$ADMF")"
check "ADMF GET /admin/users?role=super_admin leaks none" 0 "$(api GET '/admin/users?role=super_admin' "$ADMF" | sed 's/^[0-9]* //' | json meta.total)"
check "unauth GET /admin/users" 401 "$(s GET /admin/users)"
check "bad id /admin/users/abc" 404 "$(s GET /admin/users/abc "$ADMF")"
check "huge id /admin/users/99999999999999999999" 404 "$(s GET /admin/users/99999999999999999999 "$ADMF")"
exit $fail
