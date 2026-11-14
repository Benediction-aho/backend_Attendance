#!/usr/bin/env bash
# Timing oracle check on /auth/login and /auth/super-admin/code: known vs unknown email.
# Resets lockout buckets and limiters between samples (local DB only).
# Usage: PGPASSWORD=... ./timing_login.sh KNOWN_EMAIL [ROUNDS=20]
source "$(dirname "$0")/lib.sh"
KNOWN=$1; R=${2:-20}
Q="psql -h 127.0.0.1 -U attendance -d attendance -qtA -c"
t() { curl -s -o /dev/null -w '%{time_total}\n' -X POST "$B$1" -H 'Accept: application/json' -H 'Content-Type: application/json' -d "$2"; }
med() { sort -n | awk '{a[NR]=$1} END {print a[int((NR+1)/2)]}'; }
for kind in known unknown; do
  for i in $(seq 1 "$R"); do
    $Q "delete from login_attempts; delete from cache;" >/dev/null
    e=$KNOWN; [ $kind = unknown ] && e="nobody$RANDOM@test.local"
    t /auth/login "{\"email\":\"$e\",\"password\":\"WrongPass$i\",\"device_id\":\"timing-device-$RANDOM$RANDOM\"}"
  done | med | sed "s/^/login $kind median s: /"
  for i in $(seq 1 "$R"); do
    $Q "delete from cache;" >/dev/null
    e=$KNOWN; [ $kind = unknown ] && e="nobody$RANDOM@test.local"
    t /auth/super-admin/code "{\"email\":\"$e\"}"
  done | med | sed "s/^/otp-request $kind median s: /"
done
