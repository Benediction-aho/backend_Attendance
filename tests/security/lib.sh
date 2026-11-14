#!/usr/bin/env bash
# Helpers for the phase 1 security scripts. LOCAL ONLY.
# Server: APP_ENV=production APP_DEBUG=false QUEUE_CONNECTION=sync MAIL_MAILER=log \
#         PHP_CLI_SERVER_WORKERS=16 php artisan serve --port=8181
# Codes and links are read from storage/logs/laravel.log (log mailer, no real email is sent).
set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
B="${B:-http://localhost:8181/api}"
LOG="$ROOT/storage/logs/laravel.log"

# api METHOD PATH [TOKEN] [JSON] -> prints "STATUS BODY"
api() {
  local m=$1 p=$2 t=${3:-} d=${4:-}
  local args=(-s -o /dev/stdout -w ' %{http_code}' -X "$m" "$B$p" -H 'Accept: application/json')
  [ -n "$t" ] && args+=(-H "Authorization: Bearer $t")
  [ -n "$d" ] && args+=(-H 'Content-Type: application/json' -d "$d")
  local out; out=$(curl "${args[@]}")
  echo "${out##* } ${out% *}"
}

# Last text-part mail body sent to EMAIL (from the log mailer).
last_mail() {
  php -r '
    $log = file_get_contents($argv[1]); $to = $argv[2];
    $parts = preg_split("/\n\[\d{4}-\d\d-\d\d [^\]]+\] \w+\.DEBUG: /", "\n".$log);
    $hit = "";
    foreach ($parts as $p) { if (preg_match("/^To: ".preg_quote($to, "/")."\s*$/mi", $p)) { $hit = $p; } }
    echo str_replace("\r", "", quoted_printable_decode($hit));
  ' "$LOG" "$1"
}
otp_of()      { last_mail "$1" | grep -A2 'one-time code to sign in' | grep -Eo '^[0-9]{6,12}$' | head -1; }
idcode_of()   { last_mail "$1" | grep -Eo '\b[A-HJ-NP-Z2-9]{10}\b' | head -1; }
link_token_of() { last_mail "$1" | grep -Eo 'token=[A-Za-z0-9]{64}' | head -1 | cut -d= -f2; }

dev() { echo "dev-$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')"; }

sa_login() { # email -> token
  api POST /auth/super-admin/code '' "{\"email\":\"$1\"}" >/dev/null
  local c; c=$(otp_of "$1")
  api POST /auth/login '' "{\"email\":\"$1\",\"password\":\"$c\",\"device_id\":\"${2:-$(dev)}\"}" | sed 's/^[0-9]* //' | php -r 'echo json_decode(stream_get_contents(STDIN))->token ?? "";'
}
json() { php -r '$j=json_decode(stream_get_contents(STDIN),true); $k=explode(".",$argv[1]); foreach($k as $x){$j=$j[$x]??null;} echo is_scalar($j)?$j:json_encode($j);' "$1"; }
