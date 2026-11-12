<?php

use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

const OFFICE_LAT = 5.595223;
const OFFICE_LNG = -0.216703;

/** Freezes the server clock at a local Accra time. 2026-11-16 is a Monday. */
function at(string $local): void
{
    Carbon::setTestNow(Carbon::parse($local, 'Africa/Accra'));
}

/** Two-step GPS check-in at the office; returns the confirm response. */
function gpsCheckIn(object $test, User $user, float $lat = OFFICE_LAT, float $lng = OFFICE_LNG, float $accuracy = 10)
{
    Sanctum::actingAs($user);
    $intent = $test->postJson('/api/attendance/check-in/intent', ['type' => 'gps', 'latitude' => $lat, 'longitude' => $lng, 'accuracy' => $accuracy]);
    if ($intent->status() !== 201) {
        return $intent;
    }

    return $test->postJson('/api/attendance/check-in/confirm', ['intent_id' => $intent->json('intent_id')]);
}
