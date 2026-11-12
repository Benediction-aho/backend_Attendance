<?php

use Illuminate\Support\Facades\Schedule;

// Hourly tick; the task itself runs once per day (run key in scheduled_runs), so a missed hour is caught up.
Schedule::command('attendance:purge-logs')->hourly()->withoutOverlapping();

// Attendance (phase 3): every minute; each run is recorded once per work date, missed runs are caught up.
Schedule::command('attendance:auto-checkout')->everyMinute()->withoutOverlapping();
Schedule::command('attendance:checkin-reminder')->everyMinute()->withoutOverlapping();
