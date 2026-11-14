<?php

use App\Support\TimeFormat;
use Illuminate\Support\Carbon;

it('formats times on the 12-hour clock', function (string $in, string $out) {
    expect(TimeFormat::time($in))->toBe($out);
})->with([
    ['00:00', '12:00 AM'],
    ['08:10', '8:10 AM'],
    ['12:00', '12:00 PM'],
    ['17:00', '5:00 PM'],
    ['23:59', '11:59 PM'],
]);

it('formats date-times on the 12-hour clock', function () {
    expect(TimeFormat::dateTime(Carbon::parse('2026-11-13 17:05', 'Africa/Accra')))->toBe('Friday 13 November 2026, 5:05 PM');
});
