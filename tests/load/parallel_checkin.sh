#!/usr/bin/env bash
# Without k6: N parallel check-ins (intent + 2 parallel confirmations each) with curl.
# Usage: tests/load/parallel_checkin.sh http://127.0.0.1:8000/api storage/app/private/load-tokens.json [lat] [lng]
set -euo pipefail
BASE=${1:-http://127.0.0.1:8000/api}; TOKENS=${2:-storage/app/private/load-tokens.json}
LAT=${3:-5.595223}; LNG=${4:-.0}; [ "$LNG" = ".0" ] && LNG=-0.216703
OUT=$(mktemp -d)
one() {
  local i=$1 t=$2
  local h=(-s -H "Authorization: Bearer $t" -H 'Content-Type: application/json' -H 'Accept: application/json')
  local intent; intent=$(curl "${h[@]}" -o - -w '\n%{http_code}' -X POST "$BASE/attendance/check-in/intent" -d "{\"type\":\"gps\",\"latitude\":$LAT,\"longitude\":$LNG,\"accuracy\":10}")
  local code=${intent##*$'\n'}; echo "intent $code" >> "$OUT/$i"
  [ "$code" = 201 ] || return 0
  local id; id=$(echo "${intent%$'\n'*}" | sed -E 's/.*"intent_id":"([^"]+)".*/\1/')
  curl "${h[@]}" -o /dev/null -w 'confirm %{http_code}\n' -X POST "$BASE/attendance/check-in/confirm" -d "{\"intent_id\":\"$id\"}" >> "$OUT/$i" &
  curl "${h[@]}" -o /dev/null -w 'confirm %{http_code}\n' -X POST "$BASE/attendance/check-in/confirm" -d "{\"intent_id\":\"$id\"}" >> "$OUT/$i" &
  wait
}
export -f one; export BASE LAT LNG OUT
python3 -c "import json,sys;[print(i,t) for i,t in enumerate(json.load(open(sys.argv[1])))]" "$TOKENS" \
  | xargs -P 400 -n 2 bash -c 'one "$0" "$1"'
cat "$OUT"/* | sort | uniq -c
rm -rf "$OUT"
