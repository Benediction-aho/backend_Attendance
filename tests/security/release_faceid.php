<?php

// Release audit – Face ID + check-in abuse over HTTP (LOCAL ONLY, server with default WEBAUTHN_RP_ID=localhost).
// Usage: B=http://localhost:8282/api E1=<token> E2=<token> FA=<approve_faceid token> SA=<token> php tests/security/release_faceid.php
require __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/../Support/webauthn.php';
$B = getenv('B');
function api(string $m, string $p, ?string $t, $body = null): array
{
    global $B;
    $ch = curl_init($B.$p);
    $h = ['Accept: application/json', 'Content-Type: application/json'];
    if ($t) {
        $h[] = "Authorization: Bearer $t";
    }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $m, CURLOPT_HTTPHEADER => $h, CURLOPT_RETURNTRANSFER => true]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body));
    }
    $r = curl_exec($ch);

    return [curl_getinfo($ch, CURLINFO_HTTP_CODE), json_decode((string) $r, true) ?? $r];
}
$fails = 0;
function check(string $n, $exp, array $r): void
{
    global $fails;
    $ok = in_array($r[0], (array) $exp, true);
    $fails += $ok ? 0 : 1;
    echo ($ok ? 'PASS ' : 'FAIL ')."$n ({$r[0]}) ".substr(json_encode($r[1]), 0, $ok ? 110 : 300)."\n";
}
$E1 = getenv('E1');
$E2 = getenv('E2');
$FA = getenv('FA');
$SA = getenv('SA');
$OFF = ['type' => 'gps', 'latitude' => 5.595223, 'longitude' => -0.216703, 'accuracy' => 5];
$reg = function (string $t, VirtualAuthenticator $a, array $over = [], ?callable $mut = null) {
    $o = api('POST', '/me/faceid/register/options', $t);
    if ($o[0] !== 201) {
        return $o;
    }
    $c = $a->create($o[1]['public_key'], $over);
    if ($mut) {
        $c = $mut($c);
    }

    return api('POST', '/me/faceid/register', $t, ['challenge_id' => $o[1]['challenge_id'], 'credential' => $c, 'name' => 'rel']);
};
$a1 = new VirtualAuthenticator('localhost', 'http://localhost:5173');
$a2 = new VirtualAuthenticator('localhost', 'http://localhost:5173');
check('E1 register first device', [200, 201, 409], $r = $reg($E1, $a1));
echo json_encode($r[1])."\n";
check('E2 register first device', [200, 201], $reg($E2, $a2));
echo json_encode(api('GET', '/me/faceid', $E1)[1])."\n";

// Out-of-perimeter / bad accuracy / extreme coordinates
check('far away (Lagos)', [403, 422], api('POST', '/attendance/check-in/intent', $E1, ['type' => 'gps', 'latitude' => 6.5244, 'longitude' => 3.3792, 'accuracy' => 5]));
check('accuracy 5000 at office', [403, 422], api('POST', '/attendance/check-in/intent', $E1, array_merge($OFF, ['accuracy' => 5000])));
check('accuracy 101', [403, 422], api('POST', '/attendance/check-in/intent', $E1, array_merge($OFF, ['accuracy' => 101])));
check('accuracy negative', 422, api('POST', '/attendance/check-in/intent', $E1, array_merge($OFF, ['accuracy' => -1])));
check('lat NaN string', 422, api('POST', '/attendance/check-in/intent', $E1, array_merge($OFF, ['latitude' => 'NaN'])));
check('lat 1e400', 422, api('POST', '/attendance/check-in/intent', $E1, '{"type":"gps","latitude":1e400,"longitude":-0.216703,"accuracy":5}'));
check('lat as array', 422, api('POST', '/attendance/check-in/intent', $E1, array_merge($OFF, ['latitude' => [5.5]])));

// Intent + assertion
$i = api('POST', '/attendance/check-in/intent', $E1, $OFF + ['work_date' => '2026-01-01', 'check_in_at' => '2026-11-13T07:00:00Z']);
check('E1 intent at office', [200, 201], $i);
$pk = $i[1]['faceid_options']['public_key'] ?? null;
if (! $pk) {
    echo 'intent shape: '.json_encode($i[1])."\n";
    exit(1);
}
$iid = $i[1]['intent_id'] ?? $i[1]['id'] ?? null;
check('confirm without assertion', [403, 422], api('POST', '/attendance/check-in/confirm', $E1, ['intent_id' => $iid]));
check('confirm with E2 credential (other user)', [403, 422], api('POST', '/attendance/check-in/confirm', $E1, ['intent_id' => $iid, 'assertion' => $a2->get($pk)]));
check('confirm wrong origin', [403, 422], api('POST', '/attendance/check-in/confirm', $E1, ['intent_id' => $iid, 'assertion' => $a1->get($pk, ['origin' => 'https://evil.example'])]));
check('confirm no UV flag', [403, 422], api('POST', '/attendance/check-in/confirm', $E1, ['intent_id' => $iid, 'assertion' => $a1->get($pk, ['flags' => 0x01])]));
check('confirm E1 intent with E2 token', [403, 404, 422], api('POST', '/attendance/check-in/confirm', $E2, ['intent_id' => $iid, 'assertion' => $a1->get($pk)]));
// Every failed confirm burns the intent/challenge: a fresh intent is needed.
$i = api('POST', '/attendance/check-in/intent', $E1, $OFF);
$pk = $i[1]['faceid_options']['public_key'];
$iid = $i[1]['intent_id'];
$good = $a1->get($pk);
$c = api('POST', '/attendance/check-in/confirm', $E1, ['intent_id' => $iid, 'assertion' => $good]);
check('confirm valid', [200, 201], $c);
echo substr(json_encode($c[1]['data'] ?? $c[1]), 0, 400)."\n";
check('replay same assertion', [200, 403, 409, 422], $rp = api('POST', '/attendance/check-in/confirm', $E1, ['intent_id' => $iid, 'assertion' => $good]));
echo 'replay created flag: '.json_encode($rp[1]['created'] ?? null)."\n";
check('second intent same day', [409, 422], api('POST', '/attendance/check-in/intent', $E1, $OFF));

// Parallel double-submit on E2: one intent, 10 confirms at once
$i2 = api('POST', '/attendance/check-in/intent', $E2, $OFF);
$pk2 = $i2[1]['faceid_options']['public_key'] ?? null;
$iid2 = $i2[1]['intent_id'] ?? null;
$mh = curl_multi_init();
$hs = [];
for ($k = 0; $k < 10; $k++) {
    $ch = curl_init($B.'/attendance/check-in/confirm');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json', "Authorization: Bearer $E2"],
        CURLOPT_POSTFIELDS => json_encode(['intent_id' => $iid2, 'assertion' => $a2->get($pk2)])]);
    curl_multi_add_handle($mh, $ch);
    $hs[] = $ch;
}
do {
    curl_multi_exec($mh, $run);
    curl_multi_select($mh);
} while ($run);
$codes = array_map(fn ($h) => curl_getinfo($h, CURLINFO_HTTP_CODE), $hs);
sort($codes);
echo 'parallel confirms E2: '.implode(',', $codes)."\n";
echo 'E2 history: '.json_encode(api('GET', '/attendance/history', $E2)[1]['meta'] ?? null)."\n";
echo "fails=$fails\n";
