<?php

namespace App\Settings;

/**
 * Every configurable business value, with its type, default (SPECS section 3, security.md) and validation.
 * Nothing in the code may hardcode one of these values: read it through SettingsService.
 */
final class SettingDefinitions
{
    /**
     * @return array<string, array{type: string, default: mixed, rules: list<string>, group: string, label: string, editable?: bool}>
     */
    public static function all(): array
    {
        return [
            // Office and attendance
            'office_latitude' => ['type' => 'float', 'default' => 5.595223, 'rules' => ['numeric', 'between:-90,90'], 'group' => 'attendance', 'label' => 'Office GPS latitude'],
            'office_longitude' => ['type' => 'float', 'default' => -0.216703, 'rules' => ['numeric', 'between:-180,180'], 'group' => 'attendance', 'label' => 'Office GPS longitude'],
            'checkin_radius_m' => ['type' => 'int', 'default' => 50, 'rules' => ['integer', 'min:10', 'max:5000'], 'group' => 'attendance', 'label' => 'Check-in perimeter radius (m)'],
            'late_after' => ['type' => 'time', 'default' => '08:10', 'rules' => ['date_format:H:i'], 'group' => 'attendance', 'label' => 'Lateness starts at'],
            'checkin_reminder_time' => ['type' => 'time', 'default' => '08:05', 'rules' => ['date_format:H:i'], 'group' => 'attendance', 'label' => 'Check-in reminder time'],
            'faceid_required' => ['type' => 'bool', 'default' => true, 'rules' => ['boolean'], 'group' => 'attendance', 'label' => 'Face ID verification at check-in'],
            'end_of_day_time' => ['type' => 'time', 'default' => '17:00', 'rules' => ['date_format:H:i'], 'group' => 'attendance', 'label' => 'End of working day (early departure before)'],
            'auto_checkout_time' => ['type' => 'time', 'default' => '00:00', 'rules' => ['date_format:H:i'], 'group' => 'attendance', 'label' => 'Automatic check-out (day reset)'],
            'track_work_hours' => ['type' => 'bool', 'default' => false, 'rules' => ['boolean'], 'group' => 'attendance', 'label' => 'Track working hours'],
            'early_checkout_reason_required' => ['type' => 'bool', 'default' => true, 'rules' => ['boolean'], 'group' => 'attendance', 'label' => 'Reason required for early check-out'],
            'max_days_off_per_week' => ['type' => 'int', 'default' => 2, 'rules' => ['integer', 'min:0', 'max:6'], 'group' => 'attendance', 'label' => 'Maximum days off per week'],

            // Reports
            'daily_report_time' => ['type' => 'time', 'default' => '17:00', 'rules' => ['date_format:H:i'], 'group' => 'reports', 'label' => 'Daily report generation time'],
            'weekly_window_day' => ['type' => 'int', 'default' => 5, 'rules' => ['integer', 'between:1,7'], 'group' => 'reports', 'label' => 'Weekly report day (1 = Monday … 7 = Sunday)'],
            'weekly_window_start' => ['type' => 'time', 'default' => '16:00', 'rules' => ['date_format:H:i'], 'group' => 'reports', 'label' => 'Weekly report window opens'],
            'weekly_window_end' => ['type' => 'time', 'default' => '17:00', 'rules' => ['date_format:H:i'], 'group' => 'reports', 'label' => 'Weekly report window closes'],
            'weekly_reminder_time' => ['type' => 'time', 'default' => '16:30', 'rules' => ['date_format:H:i'], 'group' => 'reports', 'label' => 'Weekly report reminder time'],
            'monthly_report_day' => ['type' => 'int', 'default' => 26, 'rules' => ['integer', 'between:1,28'], 'group' => 'reports', 'label' => 'Monthly report day of month'],
            'monthly_report_time' => ['type' => 'time', 'default' => '19:00', 'rules' => ['date_format:H:i'], 'group' => 'reports', 'label' => 'Monthly report sending time'],
            'monthly_report_recipient_ids' => ['type' => 'int_list', 'default' => [], 'rules' => ['array'], 'group' => 'reports', 'label' => 'Additional monthly report recipients (admin ids)'],
            'report_pdf_max_mb' => ['type' => 'int', 'default' => 10, 'rules' => ['integer', 'min:1', 'max:50'], 'group' => 'reports', 'label' => 'Maximum PDF report size (MB)'],
            'email_attachments_max_mb' => ['type' => 'int', 'default' => 30, 'rules' => ['integer', 'min:1', 'max:40'], 'group' => 'reports', 'label' => 'Maximum email attachments size (MB)'],

            // System
            'timezone' => ['type' => 'string', 'default' => 'Africa/Accra', 'rules' => ['timezone'], 'group' => 'system', 'label' => 'Time zone', 'editable' => false],
            'log_retention_months' => ['type' => 'int', 'default' => 12, 'rules' => ['integer', 'min:1', 'max:120'], 'group' => 'system', 'label' => 'Log retention (months)'],
            'photo_max_kb' => ['type' => 'int', 'default' => 5120, 'rules' => ['integer', 'min:100', 'max:20480'], 'group' => 'system', 'label' => 'Maximum profile photo size (KB)'],
            'file_url_ttl_minutes' => ['type' => 'int', 'default' => 10, 'rules' => ['integer', 'min:1', 'max:120'], 'group' => 'system', 'label' => 'Temporary file link lifetime (minutes)'],

            // Security
            'lockout_failures_per_series' => ['type' => 'int', 'default' => 5, 'rules' => ['integer', 'min:3', 'max:20'], 'group' => 'security', 'label' => 'Failed logins per series'],
            'lockout_block_minutes' => ['type' => 'int_list', 'default' => [10, 20], 'rules' => ['array', 'min:1', 'max:5'], 'group' => 'security', 'label' => 'Block duration after each series (minutes); the next series locks'],
            'lockout_multi_device_threshold' => ['type' => 'int', 'default' => 2, 'rules' => ['integer', 'min:2', 'max:10'], 'group' => 'security', 'label' => 'Locked device buckets that lock the whole account'],
            'ip_login_failures_limit' => ['type' => 'int', 'default' => 30, 'rules' => ['integer', 'min:5', 'max:1000'], 'group' => 'security', 'label' => 'Failed logins per IP per window'],
            'ip_login_window_minutes' => ['type' => 'int', 'default' => 15, 'rules' => ['integer', 'min:1', 'max:1440'], 'group' => 'security', 'label' => 'Per-IP window (minutes)'],
            'identification_code_ttl_hours' => ['type' => 'int', 'default' => 48, 'rules' => ['integer', 'min:1', 'max:720'], 'group' => 'security', 'label' => 'Identification code validity (hours)'],
            'identification_code_length' => ['type' => 'int', 'default' => 10, 'rules' => ['integer', 'min:8', 'max:20'], 'group' => 'security', 'label' => 'Identification code length'],
            'otp_ttl_minutes' => ['type' => 'int', 'default' => 10, 'rules' => ['integer', 'min:1', 'max:60'], 'group' => 'security', 'label' => 'Super admin login code validity (minutes)'],
            'otp_length' => ['type' => 'int', 'default' => 8, 'rules' => ['integer', 'min:6', 'max:12'], 'group' => 'security', 'label' => 'Super admin login code length'],
            'otp_requests_per_hour' => ['type' => 'int', 'default' => 5, 'rules' => ['integer', 'min:1', 'max:60'], 'group' => 'security', 'label' => 'Login code requests per hour'],
            'recovery_link_ttl_minutes' => ['type' => 'int', 'default' => 30, 'rules' => ['integer', 'min:5', 'max:1440'], 'group' => 'security', 'label' => 'Recovery link validity (minutes)'],
            'email_change_ttl_minutes' => ['type' => 'int', 'default' => 60, 'rules' => ['integer', 'min:5', 'max:1440'], 'group' => 'security', 'label' => 'Email change link validity (minutes)'],
            'password_min_length' => ['type' => 'int', 'default' => 10, 'rules' => ['integer', 'min:8', 'max:64'], 'group' => 'security', 'label' => 'Minimum password length'],
            'session_idle_days' => ['type' => 'int', 'default' => 30, 'rules' => ['integer', 'min:1', 'max:365'], 'group' => 'security', 'label' => 'Device session expires after inactivity (days)'],
            'signup_requests_per_hour' => ['type' => 'int', 'default' => 5, 'rules' => ['integer', 'min:1', 'max:100'], 'group' => 'security', 'label' => 'Sign-up requests per hour per IP'],
            'api_requests_per_minute' => ['type' => 'int', 'default' => 120, 'rules' => ['integer', 'min:30', 'max:10000'], 'group' => 'security', 'label' => 'API requests per minute per user/IP'],
        ];
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::all());
    }
}
