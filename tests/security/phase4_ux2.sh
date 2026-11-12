#!/usr/bin/env bash
# Phase 2 UX backend fixes re-checked from a security angle (UX2-01/02/07/08). LOCAL ONLY.
# Server: see phase4_faceid_push.php. Mail = log mailer (no real email).
# Needs a fresh limiter state: php artisan cache:clear ; also clears login_attempts of the test accounts.
source "$(dirname "$0")/lib.sh"
tinker() { (cd "$ROOT" && php artisan tinker --execute="$1"); }
check() { local s=${3%% *}; if [ "$s" = "$2" ]; then echo "PASS $1 ($s)"; else echo "FAIL $1 expected $2 got: ${3:0:300}"; fi; }

echo "== UX2-01 code whitespace does not weaken lockout"
E=p4-ux2-code@sectest.invalid
tinker "\$u=App\Models\User::where('email','$E')->first() ?? new App\Models\User; \$u->fill(['first_name'=>'U','last_name'=>'X','email'=>'$E','role'=>App\Enums\Role::Employee,'employee_type'=>'employee','status'=>App\Enums\UserStatus::Active]); \$u->password=Illuminate\Support\Facades\Hash::make('ABCDEFGHJK'); \$u->must_change_password=true; \$u->code_expires_at=now()->addDay(); \$u->save(); App\Models\LoginAttempt::where('account_key', App\Services\Auth\LockoutService::accountKey('$E'))->delete();" >/dev/null
D=$(dev)
for c in 'ABCDE FGHJX' ' ABCDEFGHJX ' $'ABCDE\tFGHJX' 'abcdefghjk' 'ABCDE​FGHJK'; do
  r=$(api POST /auth/login '' "$(php -r 'echo json_encode(["email"=>$argv[1],"password"=>$argv[2],"device_id"=>$argv[3]]);' "$E" "$c" "$D")"); echo "  wrong variant -> ${r%% *}"
done
check "correct code after 5 whitespace-variant failures is blocked" 429 "$(api POST /auth/login '' "{\"email\":\"$E\",\"password\":\"ABCDE FGHJK\",\"device_id\":\"$D\"}")"
# All unknown devices of an account share one bucket (security.md §1), so a new unknown device is blocked too (by design).
check "spaced correct code from another unknown device (shared unknown bucket)" 429 "$(api POST /auth/login '' "{\"email\":\"$E\",\"password\":\" AB CDE FGH JK \",\"device_id\":\"$(dev)\"}")"
check "very long whitespace password" 422 "$(api POST /auth/login '' "{\"email\":\"$E\",\"password\":\"$(printf ' %.0s' {1..5000})\",\"device_id\":\"$(dev)\"}")"

echo "== UX2-02 sign-up limits (per email 3/h, per IP 50/h)"
body() { echo "{\"email\":\"$1\",\"first_name\":\"A\",\"last_name\":\"B\",\"employee_type\":\"employee\",\"job_title\":\"T\"}"; }
for i in 1 2 3; do api POST /auth/register '' "$(body "p4-su-a@sectest.invalid")" >/dev/null; done
r=$(api POST /auth/register '' "$(body "P4-SU-A@sectest.invalid")"); check "4th sign-up same email (case changed)" 429 "$r"
for i in 1 2 3; do api POST /auth/register '' "$(body "p4-load-0001@x.invalid")" >/dev/null; done
r2=$(api POST /auth/register '' "$(body "load-0001@loadtest.invalid")")
for i in 1 2; do api POST /auth/register '' "$(body "load-0001@loadtest.invalid")" >/dev/null; done
r2=$(api POST /auth/register '' "$(body "load-0001@loadtest.invalid")")
echo "  existing-email 429 body: ${r2#* }"; echo "  unknown-email 429 body:  ${r#* }"
n=0; for i in $(seq 1 60); do s=$(api POST /auth/register '' "$(body "p4-su-ip$i@sectest.invalid")"); [ "${s%% *}" = 429 ] && { n=$i; break; }; done
echo "  first per-IP 429 at request #$n (after the 8 above; expected 50 total)"
check "X-Forwarded-For does not reset the IP limit" 429 "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/auth/register" -H 'Accept: application/json' -H 'Content-Type: application/json' -H 'X-Forwarded-For: 10.9.8.7' -d "$(body p4-su-xff@sectest.invalid)")"

echo "== UX2-07 super admin code 429 identical for known/unknown"
SAE=$(cd "$ROOT" && php artisan tinker --execute="echo App\Models\User::where('role','super_admin')->where('email','like','p3-sa%')->value('email');")
for i in $(seq 1 6); do k=$(api POST /auth/super-admin/code '' "{\"email\":\"$SAE\"}"); u=$(api POST /auth/super-admin/code '' '{"email":"p4-nobody@sectest.invalid"}'); done
echo "  known:   $k"; echo "  unknown: $u"
kb=$(echo "${k#* }" | sed 's/"retry_after_seconds":[0-9]*//'); ub=$(echo "${u#* }" | sed 's/"retry_after_seconds":[0-9]*//')
[ "${k%% *}" = 429 ] && [ "$kb" = "$ub" ] && echo "PASS identical 429" || echo "FAIL 429 differs"

echo "== UX2-08 recovery during temporary block is rate limited"
tinker "App\Models\User::where('email','$SAE')->first()->forceFill(['status'=>App\Enums\UserStatus::Active])->save();" >/dev/null
D=$(dev); for i in 1 2 3 4 5; do api POST /auth/login '' "{\"email\":\"$SAE\",\"password\":\"00000000\",\"device_id\":\"$D\"}" >/dev/null; done
before=$(grep -c "To: $SAE" "$LOG")
for i in $(seq 1 20); do r=$(api POST /auth/recovery '' "{\"email\":\"$SAE\"}"); done
after=$(grep -c "To: $SAE" "$LOG")
echo "  last answer: $r ; mails to SA during 20 recovery requests: $((after-before)) (expected <= otp_requests_per_hour = 5)"
[ $((after-before)) -le 5 ] && echo "PASS recovery limited" || echo "FAIL recovery not limited"
u=$(api POST /auth/recovery '' '{"email":"p4-nobody@sectest.invalid"}'); echo "  unknown email answer: $u"
