#!/usr/bin/env bash
# Phase 5 HTTP checks against a LOCAL production-mode server (own DB, log mailer, no real email):
#   DB_DATABASE=attendance_sec5 APP_ENV=production APP_DEBUG=false QUEUE_CONNECTION=sync MAIL_MAILER=log \
#   PHP_CLI_SERVER_WORKERS=16 php artisan serve --port=8183
# Prereq: DB_DATABASE=attendance_sec5 php tests/security/phase5_fixtures.php > $F  (prints "EMP_TOKEN EMP_ID")
# Usage: F=$F bash tests/security/phase5_http.sh
set -u
B=${B:-http://localhost:8183/api}
read -r T UID_ < "$F"
H=(-H 'Accept: application/json' -H "Authorization: Bearer $T")
echo "== parallel task creation vs tasks_max_per_day (setting forced to 5 by fixtures)"
for i in $(seq 1 30); do curl -s -o /dev/null -w '%{http_code}\n' "${H[@]}" -H 'Content-Type: application/json' -d "{\"title\":\"race $i\"}" "$B/me/tasks" & done | sort | uniq -c
wait
echo "tasks stored today: $(curl -s "${H[@]}" "$B/me/tasks" | php -r 'echo count(json_decode(stream_get_contents(STDIN))->data);')"
echo "== parallel client creation vs clients_max_per_day (5)"
for i in $(seq 1 30); do curl -s -o /dev/null -w '%{http_code}\n' "${H[@]}" -H 'Content-Type: application/json' -d '{"name":"c","service":"Visa","quantity":1}' "$B/me/clients" & done | sort | uniq -c
wait
echo "clients stored today: $(curl -s "${H[@]}" "$B/me/clients" | php -r 'echo count(json_decode(stream_get_contents(STDIN))->data);')"
echo "== invalid JSON / oversized body"
curl -s -o /dev/null -w 'invalid json %{http_code}\n' "${H[@]}" -H 'Content-Type: application/json' -d '{"title":' "$B/me/tasks"
head -c 3000000 /dev/zero | tr '\0' a | sed 's/^/{"title":"/;s/$/"}/' > /tmp/big.json
curl -s -o /dev/null -w 'big json %{http_code}\n' "${H[@]}" -H 'Content-Type: application/json' --data-binary @/tmp/big.json "$B/me/tasks"; rm -f /tmp/big.json
echo "== upload headers (window state depends on the real clock)"
printf '%%PDF-1.4\n<html><script>alert(1)</script></html>\n%%%%EOF\n' > /tmp/poly.pdf
curl -s "${H[@]}" -F "file=@/tmp/poly.pdf;type=text/html;filename=../../x.html" "$B/me/reports/weekly/submission"; echo
URL=$(curl -s "${H[@]}" "$B/me/reports/weekly/submission" | php -r '$d=json_decode(stream_get_contents(STDIN))->data; echo $d->current->file_url ?? "";')
[ -n "$URL" ] && curl -s -D - -o /dev/null "$URL" | grep -iE '^(HTTP|content-type|content-disposition|content-security|x-content|cache|x-powered|server)'
rm -f /tmp/poly.pdf
