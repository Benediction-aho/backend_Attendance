<?php

use Illuminate\Support\Facades\Schedule;

// Hourly tick; the task itself runs once per day (run key in scheduled_runs), so a missed hour is caught up.
Schedule::command('attendance:purge-logs')->hourly()->withoutOverlapping();
