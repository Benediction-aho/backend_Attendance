#!/usr/bin/env bash
# Phase 6 HTTP checks (LOCAL ONLY). Usage: tests/security/phase6_http.sh SUPER_TOKEN EMPLOYEE_TOKEN
# Server: DB_DATABASE=attendance_sec6 APP_ENV=production APP_DEBUG=false PHP_CLI_SERVER_WORKERS=8 php artisan serve --port=8186
# Data: DB_DATABASE=attendance_sec6 php tests/security/phase6_seed.php 300 200000
set -u
B=${B:-http://localhost:8186/api}; SA=$1; EMP=$2
t() { curl -s -o /dev/null -w '%{http_code} %{time_total}s %{size_download}B\n' -H 'Accept: application/json' -H "Authorization: Bearer $1" "$B$2"; }
echo "== admin dashboard (super admin), 5 runs"; for i in 1 2 3 4 5; do t "$SA" /admin/dashboard; done
echo "== employee dashboard, 5 runs"; for i in 1 2 3 4 5; do t "$EMP" /me/dashboard; done
echo "== employee on admin dashboard / export"; t "$EMP" /admin/dashboard; t "$EMP" /admin/activity-logs/export
echo "== export headers"; curl -s -D - -o /dev/null -H "Authorization: Bearer $SA" "$B/admin/activity-logs/export?event=nothing.here" | grep -iE '^(HTTP|content-|cache-|x-|set-cookie)'
echo "== full export (time, size)"; t "$SA" /admin/activity-logs/export
echo "== rate limit: remaining calls"; for i in $(seq 1 10); do curl -s -o /dev/null -w '%{http_code} ' -H "Authorization: Bearer $SA" "$B/admin/activity-logs/export?event=nothing.here"; done; echo
