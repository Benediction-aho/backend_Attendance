#!/usr/bin/env bash
# Race on the lockout: N parallel wrong guesses for EMAIL from unknown devices.
# Every 401 response means the secret was actually checked. With 5 failures per series,
# at most 5 guesses should be checked before the 10-minute block (429).
# Usage: ./race_login.sh EMAIL [N=40]
source "$(dirname "$0")/lib.sh"
EMAIL=$1; N=${2:-40}
seq 1 "$N" | xargs -P "$N" -I{} curl -s -o /dev/null -w '%{http_code}\n' -X POST "$B/auth/login" \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d "{\"email\":\"$EMAIL\",\"password\":\"WrongGuess{}\",\"device_id\":\"race-device-{}-$RANDOM$RANDOM\"}" | sort | uniq -c
