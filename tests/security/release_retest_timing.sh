#!/usr/bin/env bash
# REL-01/02 re-test: response time of unauthenticated super admin code / recovery paths. LOCAL ONLY.
# Usage: P=8285 PATH_=/api/auth/super-admin/code N=12 bash release_retest_timing.sh email1 email2 ...
# Each request uses a fresh source IP 127.<k>.<j>.<i> so per-IP limits never apply.
P=${P:-8285}; URLP=${PATH_:-/api/auth/super-admin/code}; N=${N:-12}; OFF=${OFF:-1}
k=0
for e in "$@"; do
  k=$((k+1)); ts=()
  for i in $(seq 1 "$N"); do
    ts+=("$(curl -s -o /dev/null -w '%{time_total}' --interface 127.$((OFF)).$k.$i -X POST "localhost:$P$URLP" \
      -H 'Accept: application/json' -H 'Content-Type: application/json' -d "{\"email\":\"$e\"${EXTRA:-}}")")
  done
  printf '%-28s %s\n' "$e" "$(printf '%s\n' "${ts[@]}" | sort -n | awk '{a[NR]=$1; s+=$1} END {printf "min %.3f med %.3f max %.3f avg %.3f", a[1], a[int((NR+1)/2)], a[NR], s/NR}')"
done
