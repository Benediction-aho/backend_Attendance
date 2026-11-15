<?php

use App\Settings\SettingsService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Owner decision 2026-11-14: admins track attendance like employees, from the go-live only (no retroactive
 * effect). The setting `admin_attendance_starts_on` gets the date of this migration (Africa/Accra), effective
 * from now: no admin is expected, late or missing a report for any earlier day. Data only (two inserted rows);
 * an environment where the setting already has a value is left untouched.
 */
return new class extends Migration
{
    private const KEY = 'admin_attendance_starts_on';

    public function up(): void
    {
        if (DB::table('settings')->where('key', self::KEY)->exists()) {
            return;
        }
        $now = Carbon::now((string) config('app.timezone', 'Africa/Accra'));
        $value = json_encode($now->toDateString());

        DB::table('settings')->insert(['key' => self::KEY, 'value' => $value, 'effective_from' => $now, 'created_at' => $now]);
        DB::table('setting_history')->insert([
            'key' => self::KEY, 'old_value' => null, 'new_value' => $value, 'effective_from' => $now, 'changed_by' => null, 'changed_at' => $now,
        ]);
        $this->forgetCachedSettings();
    }

    public function down(): void
    {
        DB::table('settings')->where('key', self::KEY)->delete();
        DB::table('setting_history')->where('key', self::KEY)->delete();
        $this->forgetCachedSettings();
    }

    /** Running instances re-read the settings at once; a cache problem must never fail the migration. */
    private function forgetCachedSettings(): void
    {
        try {
            app(SettingsService::class)->flush();
        } catch (Throwable) {
            // The cached values expire by themselves within minutes.
        }
    }
};
