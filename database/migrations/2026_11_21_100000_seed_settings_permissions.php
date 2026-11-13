<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PERMISSIONS = [
        'manage_settings_attendance' => 'Settings – Attendance',
        'manage_settings_reports' => 'Settings – Reports',
        'manage_settings_security' => 'Settings – Security',
    ];

    public function up(): void
    {
        $now = now();
        foreach (self::PERMISSIONS as $key => $label) {
            DB::table('permissions')->insertOrIgnore(['key' => $key, 'label' => $label, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        DB::table('permissions')->whereIn('key', array_keys(self::PERMISSIONS))->delete();
    }
};
