#!/usr/bin/env bash
# P1-16 / P1-17 against a running local server: super admin code cap with strangers vs the owner's known device,
# then invalidation of every other outstanding code after a successful sign-in.
# Different client IPs are simulated with loopback source addresses (127.0.0.x), server bound to 127.0.0.1.
# Usage: PGPASSWORD=... SA=sa@test.local ./sa_code_cap.sh   (local DB only; resets limiters and codes of SA)
source "$(dirname "$0")/lib.sh"
SA=${SA:-sa@test.local}
KNOWN_DEV=${KNOWN_DEV:-sa-known-phone-000001}
Q="psql -h 127.0.0.1 -U attendance -d attendance -qtA -c"
fail=0
check() { if [ "$2" = "$3" ]; then echo "PASS $1 -> $3"; else echo "FAIL $1 expected $2 got $3"; fail=1; fi; }
req() { # ip json -> status
  curl -s -o /dev/null -w '%{http_code}' --interface "$1" -X POST "$B/auth/super-admin/code" \
    -H 'Accept: application/json' -H 'Content-Type: application/json' -d "$2"
}
login() { # ip code device -> status
  curl -s -o /dev/null -w '%{http_code}' --interface "$1" -X POST "$B/auth/login" \
    -H 'Accept: application/json' -H 'Content-Type: application/json' \
    -d "{\"email\":\"$SA\",\"password\":\"$2\",\"device_id\":\"$3\"}"
}
active() { $Q "select count(*) from login_codes c join users u on u.id=c.user_id where u.email='$SA' and c.used_at is null and c.expires_at>now()"; }
reset() { $Q "delete from rate_limit_counters; delete from login_attempts; delete from cache; update login_codes set used_at=now() where used_at is null;" >/dev/null; }

# 0. Owner signs in once on the known device (makes it a known device).
reset
req 127.0.0.1 "{\"email\":\"$SA\"}" >/dev/null; c=$(otp_of "$SA")
check "owner first sign-in on known device" 200 "$(login 127.0.0.1 "$c" "$KNOWN_DEV")"
reset

# 1. Three strangers (3 IPs, no or unknown device) fill the cap.
codes=()
for i in 2 3 4; do
  check "stranger 127.0.0.$i code request (neutral answer)" 202 "$(req 127.0.0.$i "{\"email\":\"$SA\",\"device_id\":\"stranger-device-00000$i\"}")"
  codes+=("$(otp_of "$SA")")
done
check "active codes after 3 strangers" 3 "$(active)"
# 2. A 4th stranger and an unknown device are refused at the cap (same neutral answer, no new code).
check "4th stranger request (neutral answer)" 202 "$(req 127.0.0.5 "{\"email\":\"$SA\"}")"
check "unknown device_id request (neutral answer)" 202 "$(req 127.0.0.6 "{\"email\":\"$SA\",\"device_id\":\"another-unknown-0000\"}")"
check "active codes still 3" 3 "$(active)"
check "last mailed code unchanged (no mail for refused requests)" "${codes[2]}" "$(otp_of "$SA")"
# 3. The owner's known device from a NEW IP gets a code; the oldest stranger code is cancelled.
check "known device from new IP (neutral answer)" 202 "$(req 127.0.0.7 "{\"email\":\"$SA\",\"device_id\":\"$KNOWN_DEV\"}")"
own=$(otp_of "$SA")
[ "$own" != "${codes[2]}" ] && echo "PASS owner got a new code" || { echo "FAIL owner got no code"; fail=1; }
check "active codes after eviction" 3 "$(active)"
check "contention logged at critical level" critical "$($Q "select level from activity_logs where event='auth.login_code_contention' and ip='127.0.0.7' order by id desc limit 1")"
check "evicted stranger code 1 refused" 401 "$(login 127.0.0.2 "${codes[0]}" stranger-device-000002)"
# 4. Owner signs in; every other outstanding code is invalidated (P1-17).
check "owner sign-in with new code" 200 "$(login 127.0.0.7 "$own" "$KNOWN_DEV")"
check "active codes after owner sign-in" 0 "$(active)"
$Q "delete from rate_limit_counters; delete from login_attempts;" >/dev/null
check "stranger code 2 refused after owner sign-in" 401 "$(login 127.0.0.3 "${codes[1]}" stranger-device-000003)"
check "stranger code 3 refused after owner sign-in" 401 "$(login 127.0.0.4 "${codes[2]}" stranger-device-000004)"
check "owner code replay refused" 401 "$(login 127.0.0.7 "$own" "$KNOWN_DEV")"
exit $fail
