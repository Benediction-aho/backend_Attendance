<?php

use App\Models\Attendance;
use App\Models\User;
use Illuminate\Http\UploadedFile;
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

/** Inserts an attendance row directly (reports tests). */
function attend(User $user, string $date, bool $late = false): void
{
    Attendance::query()->create([
        'user_id' => $user->id, 'work_date' => $date, 'day_status' => 'working', 'check_in_type' => 'gps',
        'check_in_at' => Carbon::parse($date.' '.($late ? '08:30' : '08:00'), 'Africa/Accra'), 'is_late' => $late, 'late_after' => '08:10',
    ]);
}

/** A small valid-looking PDF upload. */
function pdfUpload(string $name = 'report.pdf', int $padKb = 0): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n%test\n".str_repeat('A', $padKb * 1024)."\n%%EOF\n");
}
