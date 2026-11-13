<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Times shown to people (notifications, emails) use the 12-hour clock, e.g. "8:10 AM"
 * (owner decision). Stored values, API fields and data exports stay in 24-hour HH:MM.
 */
final class TimeFormat
{
    public static function time(CarbonInterface|string $value): string
    {
        $time = is_string($value) ? Carbon::createFromFormat('H:i', substr($value, 0, 5)) : $value;

        return $time->format('g:i A');
    }

    public static function dateTime(CarbonInterface $value): string
    {
        return $value->format('l j F Y, g:i A');
    }
}
