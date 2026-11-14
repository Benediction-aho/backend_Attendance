<?php

use Illuminate\Support\Facades\Schedule;

// Hourly tick; the task itself runs once per day (run key in scheduled_runs), so a missed hour is caught up.
Schedule::command('attendance:purge-logs')->hourly()->withoutOverlapping();

// Attendance (phase 3): every minute; each run is recorded once per work date, missed runs are caught up.
Schedule::command('attendance:auto-checkout')->everyMinute()->withoutOverlapping();
Schedule::command('attendance:checkin-reminder')->everyMinute()->withoutOverlapping();

// Reports (phase 5): every minute; each run once per run key, missed runs caught up (report_catchup_days).
Schedule::command('reports:daily')->everyMinute()->withoutOverlapping();
Schedule::command('reports:weekly-reminder')->everyMinute()->withoutOverlapping();
Schedule::command('reports:weekly-close')->everyMinute()->withoutOverlapping();
Schedule::command('reports:monthly')->everyMinute()->withoutOverlapping();
