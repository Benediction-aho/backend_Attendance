<?php

namespace App\Domain\Attendance;

/** What a work date means for an employee. Only "working" and "remote" days are expected (counted, reminded). */
enum DayStatus: string
{
    case Working = 'working';
    case Remote = 'remote';
    case DayOff = 'day_off';
    case Holiday = 'holiday';
    case Leave = 'leave';
    case NonWorking = 'non_working';

    public function isExpected(): bool
    {
        return $this === self::Working || $this === self::Remote;
    }
}
