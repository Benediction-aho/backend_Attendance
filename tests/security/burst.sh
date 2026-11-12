#!/usr/bin/env bash
# Burst of N parallel requests on a POST endpoint; prints status counts and latency p50/p95/p99.
# Local php -S server only (not representative of production latency; checks for 5xx and invariants).
# Usage: ./burst.sh PATH 'JSON with {} replaced by the request number' [N=200]
source "$(dirname "$0")/lib.sh"
P=$1; BODY=$2; N=${3:-200}
seq 1 "$N" | xargs -P "$N" -I{} curl -s -o /dev/null -w '%{http_code} %{time_total}\n' -X POST "$B$P" \
  -H 'Accept: application/json' -H 'Content-Type: application/json' -d "$BODY" > /tmp/burst.$$
cut -d' ' -f1 /tmp/burst.$$ | sort | uniq -c
cut -d' ' -f2 /tmp/burst.$$ | sort -n | awk '{a[NR]=$1} END {printf "p50=%ss p95=%ss p99=%ss\n", a[int(NR*.5)], a[int(NR*.95)], a[int(NR*.99)]}'
rm -f /tmp/burst.$$
