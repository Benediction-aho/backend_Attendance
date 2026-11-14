<?php

// Regression of tests/load/parallel_checkin.sh with Face ID required: N employees register a software
// passkey, then N intents and N confirmations (+ N duplicate confirmations) are sent concurrently.
// LOCAL ONLY. Prereq: php artisan attendance:load-fixtures --count=230. Usage: N=200 php tests/security/phase4_parallel_faceid.php
use App\Models\Attendance;
use App\Models\User;
use App\Models\WebauthnCredential;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (app()->environment('production')) {
    fwrite(STDERR, "refused\n");
    exit(1);
}
require __DIR__.'/../Support/webauthn.php';
$B = getenv('B') ?: 'http://localhost:8181/api';
$N = (int) (getenv('N') ?: 200);
$tok = array_slice(json_decode((string) file_get_contents(base_path('storage/app/private/load-tokens.json')), true), 0, $N);
$ids = User::query()->where('email', 'like', 'load-%@loadtest.invalid')->orderBy('email')->limit($N)->pluck('id');
WebauthnCredential::query()->whereIn('user_id', $ids)->delete();
Attendance::query()->whereIn('user_id', $ids)->delete();
$auth = [];
foreach ($tok as $i => $t) {
    $o = Http::withToken($t)->acceptJson()->post("$B/me/faceid/register/options")->json();
    $auth[$i] = new VirtualAuthenticator;
    $s = Http::withToken($t)->acceptJson()->post("$B/me/faceid/register", ['challenge_id' => $o['challenge_id'], 'credential' => $auth[$i]->create($o['public_key'])])->status();
    if ($s !== 201) {
        echo "register $i -> $s\n";
    }
}
$OFF = ['type' => 'gps', 'latitude' => 5.595223, 'longitude' => -0.216703, 'accuracy' => 5];
$t0 = microtime(true);
$intents = Http::pool(fn (Pool $p) => array_map(fn ($t) => $p->withToken($t)->acceptJson()->timeout(120)->post("$B/attendance/check-in/intent", $OFF), $tok));
$bodies = [];
foreach ($intents as $i => $r) {
    $j = $r->json();
    $bodies[$i] = ['intent_id' => $j['intent_id'] ?? '', 'assertion' => isset($j['faceid_options']) ? $auth[$i]->get($j['faceid_options']['public_key']) : null];
}
$lat = [];
$reqs = [];
foreach ($tok as $i => $t) {
    $reqs[] = [$i, $t];
    $reqs[] = [$i, $t]; // double submit of the same signed assertion
}
$res = Http::pool(fn (Pool $p) => array_map(fn ($x) => $p->withToken($x[1])->acceptJson()->timeout(120)
    ->post("$B/attendance/check-in/confirm", $bodies[$x[0]]), $reqs));
$codes = [];
foreach ($res as $r) {
    $k = $r instanceof Throwable ? 'exception' : $r->status();
    $codes[$k] = ($codes[$k] ?? 0) + 1;
    if (! $r instanceof Throwable) {
        $lat[] = (float) ($r->handlerStats()['total_time'] ?? 0);
    }
}
sort($lat);
$pc = fn ($q) => $lat ? round($lat[(int) floor(($q / 100) * (count($lat) - 1))] * 1000) : null;
$intentCodes = array_count_values(array_map(fn ($r) => $r instanceof Throwable ? 'exception' : $r->status(), $intents));
$rows = Attendance::query()->whereIn('user_id', $ids)->count();
$dups = Attendance::query()->whereIn('user_id', $ids)->selectRaw('user_id, work_date, count(*) c')->groupBy('user_id', 'work_date')->havingRaw('count(*) > 1')->count();
$fv = Attendance::query()->whereIn('user_id', $ids)->where('faceid_verified', true)->count();
echo 'intents: '.json_encode($intentCodes).' confirms: '.json_encode($codes)."\n";
echo "attendance rows=$rows (expected $N) faceid_verified=$fv duplicates=$dups\n";
echo 'confirm latency ms p50='.$pc(50).' p95='.$pc(95).' p99='.$pc(99).' total='.round(microtime(true) - $t0, 1)."s\n";
