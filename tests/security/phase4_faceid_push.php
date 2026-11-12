<?php

// Phase 4 security checks: WebAuthn (Face ID) abuse, review authorization, check-in gate, Web Push SSRF/IDOR.
// LOCAL ONLY. Server (production mode, no VAPID keys -> no real push is ever sent):
//   APP_ENV=production APP_DEBUG=false QUEUE_CONNECTION=sync MAIL_MAILER=log WEBAUTHN_RP_ID=attendance.test \
//   WEBAUTHN_ORIGINS=https://attendance.test VAPID_PUBLIC_KEY= VAPID_PRIVATE_KEY= PHP_CLI_SERVER_WORKERS=16 \
//   php artisan serve --port=8181
// Prereq: php artisan attendance:load-fixtures --count=230 ; P3=... php tests/security/phase3_fixtures.php > $P3
// Usage: P3=$P3 php tests/security/phase4_faceid_push.php   (uses load users 0206..0229)
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\Device;
use App\Models\PermissionRecord;
use App\Models\PushSubscription;
use App\Models\User;
use App\Models\WebauthnChallenge;
use App\Models\WebauthnCredential;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (app()->environment('production')) {
    fwrite(STDERR, "refused\n");
    exit(1);
}
require __DIR__.'/../Support/webauthn.php';

// Raise the per-user attendance limiter for the functional run (restored at the end); the limit itself is checked separately.
$settings = app(\App\Settings\SettingsService::class);
$settings->set(['checkin_attempts_per_minute' => 600], null, \Illuminate\Support\Carbon::parse('2000-01-01'));
$B = getenv('B') ?: 'http://localhost:8181/api';
$p3 = json_decode((string) file_get_contents((string) getenv('P3')), true);
$tok = json_decode((string) file_get_contents(base_path('storage/app/private/load-tokens.json')), true);
$fails = 0;
$api = function (string $m, string $path, ?string $t, ?array $body = null, ?string $raw = null) use ($B) {
    $r = Http::withHeaders(['Accept' => 'application/json'])->when($t, fn ($h) => $h->withToken($t));
    if ($raw !== null) {
        $r = $r->withBody($raw, 'application/json');
    }
    $res = $r->send($m, $B.$path, $body !== null ? ['json' => $body] : []);

    return [$res->status(), $res->json() ?? $res->body()];
};
$check = function (string $name, $expected, array $res) use (&$fails) {
    $ok = is_array($expected) ? in_array($res[0], $expected, true) : $res[0] === $expected;
    $fails += $ok ? 0 : 1;
    echo ($ok ? 'PASS ' : 'FAIL ')."$name ({$res[0]}) ".($ok ? '' : substr(json_encode($res[1]), 0, 250))."\n";
};
// Second device token for a user.
$newDeviceToken = function (User $u, string $label) {
    $d = Device::query()->firstOrCreate(['user_id' => $u->id, 'identifier_hash' => Device::hashIdentifier("p4-$label-{$u->id}")], ['first_login_at' => now(), 'last_seen_at' => now()]);
    $t = $u->createToken('p4');
    $t->accessToken->forceFill(['device_id' => $d->id])->save();

    return $t->plainTextToken;
};
$uid = fn (int $i) => User::query()->where('email', sprintf('load-%04d@loadtest.invalid', $i + 1))->value('id');
$register = function (string $t, VirtualAuthenticator $a, array $over = [], ?callable $mutate = null) use ($api) {
    $o = $api('POST', '/me/faceid/register/options', $t);
    if ($o[0] !== 201) {
        return $o;
    }
    $cred = $a->create($o[1]['public_key'], $over);
    if ($mutate) {
        $cred = $mutate($cred, $o[1]);
    }

    return $api('POST', '/me/faceid/register', $t, ['challenge_id' => $o[1]['challenge_id'], 'credential' => $cred, 'name' => 'p4']);
};
$OFF = ['type' => 'gps', 'latitude' => 5.595223, 'longitude' => -0.216703, 'accuracy' => 5];
$intent = fn (string $t, array $b = []) => $api('POST', '/attendance/check-in/intent', $t, $b ?: $OFF);

// Fixture: admin holding approve_faceid_devices (+ an employee-tier-less admin from P3).
$mk = function (string $k, Role $role, array $perms) use ($newDeviceToken) {
    $u = User::query()->where('email', "p4-$k@sectest.invalid")->first() ?? new User;
    $u->fill(['first_name' => 'P4', 'last_name' => $k, 'email' => "p4-$k@sectest.invalid", 'role' => $role, 'employee_type' => 'employee', 'status' => UserStatus::Active]);
    $u->password = Hash::make(Str::random(32));
    $u->must_change_password = false;
    $u->save();
    $u->permissionRecords()->sync(PermissionRecord::query()->whereIn('key', $perms)->pluck('id'));

    return [$u, $newDeviceToken($u, $k)];
};
[$faAdmin, $FA] = $mk('faceid', Role::Admin, ['approve_faceid_devices']);
[$faAdmin2, $FA2] = $mk('faceid2', Role::Admin, ['approve_faceid_devices']);
$SA = $p3['sa']['token'];
$NONE = $p3['none']['token'];
$OB = $p3['onbehalf']['token'];
WebauthnCredential::query()->whereIn('user_id', array_map($uid, range(206, 229)))->delete();
\App\Models\Attendance::query()->whereIn("user_id", array_map($uid, range(206, 229)))->delete();
PushSubscription::query()->whereIn("user_id", array_map($uid, range(206, 229)))->delete();
User::query()->whereIn('id', [$p3['sa']['id'], $faAdmin->id, $faAdmin2->id])->get()->each(fn ($u) => WebauthnCredential::query()->where('user_id', $u->id)->delete());

echo "== registration abuse\n";
$E = fn (int $i) => $tok[$i];
$a1 = new VirtualAuthenticator;
$check('no-UV flag', 422, $register($E(206), $a1, ['flags' => 0x41]));
$check('wrong origin', 422, $register($E(206), $a1, ['origin' => 'https://evil.test']));
$check('wrong rp id', 422, $register($E(206), $a1, ['rp_id' => 'evil.test']));
$check('type webauthn.get', 422, $register($E(206), $a1, ['type' => 'webauthn.get']));
$check('challenge substitution', 422, $register($E(206), $a1, ['challenge' => rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=')]));
$check('malformed CBOR', 422, $register($E(206), $a1, [], function ($c) {
    $c['response']['attestationObject'] = rtrim(strtr(base64_encode("\xbf\xff\x5f".random_bytes(200)), '+/', '-_'), '=');

    return $c;
}));
$check('deep nested CBOR', 422, $register($E(206), $a1, [], function ($c) {
    $c['response']['attestationObject'] = rtrim(strtr(base64_encode(str_repeat("\x81", 5000)."\x00"), '+/', '-_'), '=');

    return $c;
}));
$check('huge attestationObject (>16KB)', 422, $register($E(206), $a1, [], function ($c) {
    $c['response']['attestationObject'] = str_repeat('A', 20000);

    return $c;
}));
$check('clientData not JSON', 422, $register($E(206), $a1, [], function ($c) {
    $c['response']['clientDataJSON'] = 'bm90LWpzb24';

    return $c;
}));
$check('credential as string', 422, $api('POST', '/me/faceid/register', $E(206), ['challenge_id' => (string) Str::uuid(), 'credential' => 'x']));
$check('invalid JSON body', [400, 422], $api('POST', '/me/faceid/register', $E(206), null, '{"challenge_id":'));
// Foreign challenge: E207's challenge used by E206.
$o207 = $api('POST', '/me/faceid/register/options', $E(207));
$o206 = $api('POST', '/me/faceid/register/options', $E(206));
$check('foreign challenge id', 422, $api('POST', '/me/faceid/register', $E(206), ['challenge_id' => $o207[1]['challenge_id'], 'credential' => $a1->create($o207[1]['public_key'])]));
// Expired challenge.
WebauthnChallenge::query()->whereKey($o206[1]['challenge_id'])->update(['expires_at' => now()->subSecond()]);
$check('expired challenge', 422, $api('POST', '/me/faceid/register', $E(206), ['challenge_id' => $o206[1]['challenge_id'], 'credential' => $a1->create($o206[1]['public_key'])]));
// Valid first registration -> auto approved.
$o = $api('POST', '/me/faceid/register/options', $E(206));
$body = ['challenge_id' => $o[1]['challenge_id'], 'credential' => $a1->create($o[1]['public_key'])];
$r = $api('POST', '/me/faceid/register', $E(206), $body);
$check('first registration', 201, $r);
echo '  status='.($r[1]['data']['status'] ?? '?')."\n";
$check('replay registration', 422, $api('POST', '/me/faceid/register', $E(206), $body));
$check('options when ready -> 409', 409, $api('POST', '/me/faceid/register/options', $E(206)));
// Same authenticator (credential id) on another account.
$check('same credential on other account', [409, 422], $register($E(208), $a1));
// Other device of the same user registers -> pending; cannot check in from it.
$u206 = User::query()->findOrFail($uid(206));
$E206b = $newDeviceToken($u206, 'b');
$a1b = new VirtualAuthenticator;
$r = $register($E206b, $a1b);
$check('second device registration', 201, $r);
echo '  status='.($r[1]['data']['status'] ?? '?')."\n";
$pendingId = $r[1]['data']['id'] ?? 0;
$check('intent from pending device', 403, $intent($E206b));
// Device B tries to use device A's authenticator: intent from B refused (other_device) -> no options at all.
echo "== self-approval / review authorization\n";
$check('employee approves own pending', 403, $api('POST', "/admin/faceid/credentials/$pendingId/approve", $E206b));
$check('admin without tier approve', 403, $api('POST', "/admin/faceid/credentials/$pendingId/approve", $NONE));
$check('admin without tier list', 403, $api('GET', '/admin/faceid/credentials', $NONE));
$check('tier admin revoke (super admin only)', 403, $api('POST', "/admin/faceid/credentials/$pendingId/revoke", $FA));
// Tier admin cannot approve another admin's credential.
// Admin accounts cannot reach /me/faceid (employee routes); seed a pending credential for admin 2 directly.
$adminPending = WebauthnCredential::query()->create(['user_id' => $faAdmin2->id, 'device_id' => null, 'credential_id' => 'p4-admin-'.Str::random(10), 'public_key' => 'x', 'user_handle' => 'x', 'sign_count' => 0, 'status' => 'pending'])->id;
$check('tier admin approves other admin', 403, $api('POST', "/admin/faceid/credentials/$adminPending/approve", $FA));
$check('tier admin approves own (admin) pending', 403, $api('POST', "/admin/faceid/credentials/$adminPending/approve", $FA2));
$l = $api('GET', '/admin/faceid/credentials?status=pending&user_id='.$faAdmin2->id, $FA);
$check('tier admin list hides admin credentials', 200, $l);
echo '  visible='.count($l[1]['data'] ?? [])." (expected 0)\n";
$l = $api('GET', '/admin/faceid/credentials?status=approved', $FA);
echo '  approved list leaks public_key/credential_id: '.(str_contains(json_encode($l[1]), 'public_key') || str_contains(json_encode($l[1]), 'credential_id') ? 'YES' : 'no')."\n";
$check('unknown credential id', 404, $api('POST', '/admin/faceid/credentials/999999/approve', $FA));
$check('tier admin approves employee pending', 200, $api('POST', "/admin/faceid/credentials/$pendingId/approve", $FA));
$check('approve twice', 409, $api('POST', "/admin/faceid/credentials/$pendingId/approve", $FA));
$check('reject after approve', 409, $api('POST', "/admin/faceid/credentials/$pendingId/reject", $FA));
$check('old device A now other_device', 403, $intent($E(206)));

echo "== check-in assertion abuse (device B of user 206 approved)\n";
$i = $intent($E206b);
$check('intent with options', 201, $i);
$pk = $i[1]['faceid_options']['public_key'] ?? null;
$conf = fn (string $t, string $iid, ?array $as) => $api('POST', '/attendance/check-in/confirm', $t, array_filter(['intent_id' => $iid, 'assertion' => $as]));
$check('no assertion', 422, $conf($E206b, $i[1]['intent_id'], null));
$check('assertion with old revoked credential (device A key)', 422, $conf($E206b, $i[1]['intent_id'], $a1->get($pk)));
$check('assertion without UV', 422, $conf($E206b, $i[1]['intent_id'], $a1b->get($pk, ['flags' => 0x01])));
$check('assertion wrong origin', 422, $conf($E206b, $i[1]['intent_id'], $a1b->get($pk, ['origin' => 'https://evil.test'])));
$check('assertion foreign challenge', 422, $conf($E206b, $i[1]['intent_id'], $a1b->get($pk, ['challenge' => rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=')])));
$a1b->counter = 10;
$check('assertion counter regression (stored=0? set 5 after valid 10)', 201, $conf($E206b, $i[1]['intent_id'], $a1b->get($pk, ['counter' => 10])));
$storedCount = WebauthnCredential::query()->whereKey($pendingId)->value('sign_count');
echo "  stored sign_count=$storedCount\n";
$check('replay same assertion/intent (idempotent 200, no 2nd row)', [200, 201], $conf($E206b, $i[1]['intent_id'], $a1b->get($pk, ['counter' => 11])));
// Counter regression on a fresh day for another user: register 209, valid check-in with counter 50, then counter 3 on another intent.
\App\Models\Attendance::query()->where('user_id', $uid(206))->delete();
$i2 = $intent($E206b);
$check('counter regression (3 < 10)', 422, $conf($E206b, $i2[1]['intent_id'], $a1b->get($i2[1]['faceid_options']['public_key'], ['counter' => 3])));
$check('counter equal (10 = 10)', 422, $conf($E206b, $i2[1]['intent_id'], $a1b->get($i2[1]['faceid_options']['public_key'], ['counter' => 10])));
// Challenge replay across intents: assertion signed for intent 2 used for intent 3.
$asOld = $a1b->get($i2[1]['faceid_options']['public_key'], ['counter' => 20]);
$i3 = $intent($E206b);
$check('assertion of intent N on intent N+1', 422, $conf($E206b, $i3[1]['intent_id'], $asOld));
// Registration challenge used as check-in: assertion over a registration challenge.
$ro = $api('POST', '/me/faceid/register/options', $E(210));
$check('registration challenge used for check-in', 422, $conf($E206b, $i3[1]['intent_id'], $a1b->get(['challenge' => $ro[1]['public_key']['challenge']], ['counter' => 21])));
// Other user's intent with own valid assertion.
$check('confirm other user intent', 422, $conf($E(211), $i3[1]['intent_id'], $a1b->get($i3[1]['faceid_options']['public_key'], ['counter' => 22])));
// Other device token of same user confirming device-B intent.
$check('confirm from device A of intent created on B', 422, $conf($E(206), $i3[1]['intent_id'], $a1b->get($i3[1]['faceid_options']['public_key'], ['counter' => 23])));
// Expired challenge (intent alive).
WebauthnChallenge::query()->where('checkin_intent_id', $i3[1]['intent_id'])->update(['expires_at' => now()->subSecond()]);
$check('expired check-in challenge', 422, $conf($E206b, $i3[1]['intent_id'], $a1b->get($i3[1]['faceid_options']['public_key'], ['counter' => 24])));
$check('huge assertion payload', [413, 422], $conf($E206b, $i3[1]['intent_id'], ['id' => str_repeat('A', 500000), 'rawId' => 'x', 'type' => 'public-key', 'response' => []]));
$check('assertion garbage types', 422, $conf($E206b, $i3[1]['intent_id'], ['id' => ['x'], 'rawId' => 1, 'type' => 'public-key', 'response' => 'x']));
// Revocation takes effect immediately.
$i4 = $intent($E206b);
$api('POST', "/admin/faceid/credentials/$pendingId/revoke", $SA);
$check('confirm after revoke (intent created before)', 422, $conf($E206b, $i4[1]['intent_id'], $a1b->get($i4[1]['faceid_options']['public_key'], ['counter' => 30])));
$check('intent after revoke', 403, $intent($E206b));
$check('re-register after revoke -> must be pending', 201, $r = $register($E206b, new VirtualAuthenticator));
echo '  status='.($r[1]['data']['status'] ?? '?')." (expected pending)\n";

echo "== check-in without Face ID when required (all types)\n";
$check('gps intent not registered', 403, $intent($E(212)));
$check('mission intent not registered', 403, $intent($E(212), ['type' => 'mission', 'reason' => 'x']));
$check('remote intent not registered', 403, $intent($E(212), ['type' => 'remote']));
$a13 = new VirtualAuthenticator;
$register($E(213), $a13);
foreach ([['type' => 'mission', 'reason' => 'client visit'], ['type' => 'gps'] + $OFF] as $b) {
    $ii = $intent($E(213), $b);
    $check("{$b['type']} confirm without assertion", 422, $conf($E(213), $ii[1]['intent_id'] ?? '00000000-0000-4000-8000-000000000000', null));
}
$u214 = User::query()->findOrFail($uid(214));
$check('admin on-behalf without reason', 422, $api('POST', "/admin/users/{$u214->id}/check-in", $OB, []));
$check('admin on-behalf with reason (no Face ID)', 201, $api('POST', "/admin/users/{$u214->id}/check-in", $OB, ['reason' => 'phone broken']));
$check('admin without tier on-behalf', 403, $api('POST', "/admin/users/{$uid(215)}/check-in", $NONE, ['reason' => 'x']));

echo "== first-registration auto-approval\n";
// Race: two parallel first registrations on two devices of a fresh user.
$u216 = User::query()->findOrFail($uid(216));
$t1 = $E(216);
$t2 = $newDeviceToken($u216, 'race');
$oa = $api('POST', '/me/faceid/register/options', $t1);
$ob = $api('POST', '/me/faceid/register/options', $t2);
$pool = Http::pool(fn ($p) => [
    $p->withToken($t1)->acceptJson()->post("$B/me/faceid/register", ['challenge_id' => $oa[1]['challenge_id'], 'credential' => (new VirtualAuthenticator)->create($oa[1]['public_key'])]),
    $p->withToken($t2)->acceptJson()->post("$B/me/faceid/register", ['challenge_id' => $ob[1]['challenge_id'], 'credential' => (new VirtualAuthenticator)->create($ob[1]['public_key'])]),
]);
$st = WebauthnCredential::query()->where('user_id', $u216->id)->pluck('status')->sort()->values()->all();
echo '  parallel first registrations -> '.$pool[0]->status().'/'.$pool[1]->status().' statuses='.json_encode($st)."\n";
$n = count(array_filter($st, fn ($s) => $s === 'approved'));
$check('at most one approved after race', 1, [$n <= 1 ? 1 : 0, $st]);
// Pending supersede griefing: second-device attacker replaces victim's pending request.
$u217 = User::query()->findOrFail($uid(217));
$register($E(217), new VirtualAuthenticator);
$v = $register($newDeviceToken($u217, 'victimnew'), new VirtualAuthenticator);
$att = $register($newDeviceToken($u217, 'attacker'), new VirtualAuthenticator);
echo '  victim pending '.($v[1]['data']['id'] ?? '?').' now '.WebauthnCredential::query()->whereKey($v[1]['data']['id'] ?? 0)->value('status').' (superseded by later request)'."\n";

echo "== Web Push\n";
$sub = fn (string $ep) => ['endpoint' => $ep, 'keys' => ['p256dh' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM', 'auth' => 'tBHItJI5svbpez7KI4CCXg']];
$E2 = $E(218);
foreach ([
    'http scheme' => 'http://fcm.googleapis.com/fcm/send/x',
    'evil host' => 'https://evil.test/x',
    'suffix without dot' => 'https://evilfcm.googleapis.com/x',
    'suffix as subdomain of attacker' => 'https://fcm.googleapis.com.evil.test/x',
    'userinfo' => 'https://fcm.googleapis.com@evil.test/x',
    'userinfo reverse' => 'https://evil.test@fcm.googleapis.com/x',
    'backslash trick' => 'https://evil.test\\@fcm.googleapis.com/x',
    'explicit port' => 'https://fcm.googleapis.com:8443/x',
    'port 443 explicit' => 'https://fcm.googleapis.com:443/x',
    'IPv4 literal' => 'https://127.0.0.1/x',
    'IPv4 decimal' => 'https://2130706433/x',
    'IPv6 literal' => 'https://[::1]/x',
    'metadata' => 'https://169.254.169.254/latest',
    'trailing dot' => 'https://fcm.googleapis.com./x',
    'IDN homograph' => 'https://fcm.googleapis.cοm/x',
    'punycode' => 'https://xn--fcm-googleapis-com.evil/x',
    'fragment trick' => 'https://evil.test#.fcm.googleapis.com',
    'null byte' => "https://evil.test%00.fcm.googleapis.com/x",
    'percent host' => 'https://evil.test%2efcm.googleapis.com/x',
    'too long' => 'https://fcm.googleapis.com/'.str_repeat('a', 3000),
] as $name => $ep) {
    $check("push $name", 422, $api('POST', '/push/subscriptions', $E2, $sub($ep)));
}
$check('push valid fcm', 201, $api('POST', '/push/subscriptions', $E2, $sub('https://fcm.googleapis.com/fcm/send/p4-'.Str::random(8))));
$check('push bad keys', 422, $api('POST', '/push/subscriptions', $E2, ['endpoint' => 'https://fcm.googleapis.com/x', 'keys' => ['p256dh' => '<script>', 'auth' => 'x y']]));
for ($k = 0; $k < 12; $k++) {
    $api('POST', '/push/subscriptions', $E2, $sub("https://fcm.googleapis.com/fcm/send/p4-lim-$k"));
}
$cnt = PushSubscription::query()->where('user_id', $uid(218))->count();
$check('push limit per user (<=5)', 1, [$cnt <= 5 ? 1 : 0, $cnt]);
// Other user's subscription: unsubscribe IDOR and takeover by upsert.
$victimEp = 'https://fcm.googleapis.com/fcm/send/p4-victim-'.Str::random(8);
$api('POST', '/push/subscriptions', $E(219), $sub($victimEp));
$api('DELETE', '/push/subscriptions', $E(220), ['endpoint' => $victimEp]);
$check('unsubscribe other user endpoint (no effect)', 1, [PushSubscription::query()->where('user_id', $uid(219))->count() === 1 ? 1 : 0, '']);
$api('POST', '/push/subscriptions', $E(220), $sub($victimEp));
$owner = PushSubscription::query()->where('endpoint_hash', PushSubscription::hashEndpoint($victimEp))->value('user_id');
echo '  re-subscribing a known endpoint moves it to the caller: '.($owner === $uid(220) ? 'YES (see report)' : 'no')."\n";
$check('push unauthenticated', 401, $api('POST', '/push/subscriptions', null, $sub($victimEp)));

$settings->set(['checkin_attempts_per_minute' => 20], null, \Illuminate\Support\Carbon::parse('2000-01-01'));
$codes = [];
for ($k = 0; $k < 25; $k++) {
    $codes[] = $api('POST', '/me/faceid/register/options', $E(221))[0];
}
$check('registration options limited per user (20/min)', 429, [end($codes), '']);
echo "\nFAILS: $fails\n";
