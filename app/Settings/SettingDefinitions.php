<?php

namespace App\Settings;

/**
 * Every configurable business value, with its type, default (SPECS section 3, security.md) and validation.
 * Nothing in the code may hardcode one of these values: read it through SettingsService.
 */
final class SettingDefinitions
{
    /**
     * Settings sections: each one is managed by admins holding the matching permission (super admins: all).
     */
    public const SECTIONS = ['attendance', 'reports', 'security'];

    /**
     * Settings that only a super admin may change or see, whatever the admin's section permissions:
     * they decide who receives confidential data or what admins can see (privilege-related).
     */
    public const SUPER_ADMIN_ONLY = ['monthly_report_recipient_ids', 'logs_admins_see_super_admin_events', 'timezone', 'super_admin_promotion_requires_tier'];

    /** Section of each group; a setting may override it with its own 'section'. */
    private const GROUP_SECTIONS = ['attendance' => 'attendance', 'reports' => 'reports', 'security' => 'security', 'system' => 'security'];

    /**
     * @return array<string, array{type: string, default: mixed, rules: list<string>, item_rules?: list<string>, group: string, section: string, label: string, editable?: bool, super_admin_only: bool}>
     */
    public static function all(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $out = [];
        foreach (self::raw() as $key => $definition) {
            $definition['section'] ??= self::GROUP_SECTIONS[$definition['group']];
            $definition['super_admin_only'] = in_array($key, self::SUPER_ADMIN_ONLY, true);
            $out[$key] = $definition;
        }

        return $cache = $out;
    }

    /**
     * @return array<string, array{type: string, default: mixed, rules: list<string>, item_rules?: list<string>, group: string, section?: string, label: string, editable?: bool}>
     */
    private static function raw(): array
    {
        return [
            // Office and attendance
            'office_latitude' => ['type' => 'float', 'default' => 5.595223, 'rules' => ['numeric', 'between:-90,90'], 'group' => 'attendance', 'label' => 'Office GPS latitude'],
            'office_longitude' => ['type' => 'float', 'default' => -0.216703, 'rules' => ['numeric', 'between:-180,180'], 'group' => 'attendance', 'label' => 'Office GPS longitude'],
            'checkin_radius_m' => ['type' => 'int', 'default' => 50, 'rules' => ['integer', 'min:10', 'max:5000'], 'group' => 'attendance', 'label' => 'Check-in perimeter radius (m)'],
            'late_after' => ['type' => 'time', 'default' => '08:10', 'rules' => ['date_format:H:i'], 'group' => 'attendance', 'label' => 'Lateness starts at'],
            'push_enabled' => ['type' => 'bool', 'default' => true, 'rules' => ['boolean'], 'group' => 'system', 'label' => 'Send push notifications (a copy of every in-app notification)'],
            'push_ttl_seconds' => ['type' => 'int', 'default' => 3600, 'rules' => ['integer', 'min:60', 'max:86400'], 'group' => 'system', 'label' => 'Push notification lifetime when the phone is offline (seconds)'],
            'push_max_subscriptions_per_user' => ['type' => 'int', 'default' => 5, 'rules' => ['integer', 'min:1', 'max:20'], 'group' => 'system', 'label' => 'Maximum push subscriptions per user (oldest removed)'],
            'checkin_reminder_time' => ['type' => 'time', 'default' => '08:05', 'rules' => ['date_format:H:i'], 'group' => 'attendance', 'label' => 'Check-in reminder time'],
            'end_of_day_time' => ['type' => 'time', 'default' => '17:00', 'rules' => ['date_format:H:i'], 'group' => 'attendance', 'label' => 'End of working day (early departure before)'],
            'auto_checkout_time' => ['type' => 'time', 'default' => '00:00', 'rules' => ['date_format:H:i'], 'group' => 'attendance', 'label' => 'Automatic check-out (day reset)'],
            'track_work_hours' => ['type' => 'bool', 'default' => false, 'rules' => ['boolean'], 'group' => 'attendance', 'label' => 'Track working hours'],
            'early_checkout_reason_required' => ['type' => 'bool', 'default' => true, 'rules' => ['boolean'], 'group' => 'attendance', 'label' => 'Reason required for early check-out'],
            'checkin_max_accuracy_m' => ['type' => 'int', 'default' => 100, 'rules' => ['integer', 'min:5', 'max:1000'], 'group' => 'attendance', 'label' => 'Maximum GPS uncertainty accepted at check-in (m)'],
            'checkin_opens_at' => ['type' => 'time', 'default' => '06:00', 'rules' => ['date_format:H:i'], 'group' => 'attendance', 'label' => 'Check-in opens at (employee check-in refused before this time; admin check-in not restricted)'],
            'checkin_intent_ttl_seconds' => ['type' => 'int', 'default' => 120, 'rules' => ['integer', 'min:30', 'max:900'], 'group' => 'attendance', 'label' => 'Time to confirm a check-in after the position check (seconds)'],
            'faceid_required_for_checkin' => ['type' => 'bool', 'default' => true, 'rules' => ['boolean'], 'group' => 'attendance', 'label' => 'Face ID verification required at check-in (GPS, mission, remote)'],
            'faceid_challenge_ttl_seconds' => ['type' => 'int', 'default' => 120, 'rules' => ['integer', 'min:30', 'max:600'], 'group' => 'attendance', 'label' => 'Face ID challenge validity (seconds)'],
            'faceid_first_device_auto_approve' => ['type' => 'bool', 'default' => true, 'rules' => ['boolean'], 'group' => 'attendance', 'label' => 'First Face ID registration approved automatically (later devices need an admin)'],
            'working_days' => ['type' => 'int_list', 'default' => [1, 2, 3, 4, 5], 'rules' => ['array', 'max:7'], 'item_rules' => ['integer', 'distinct', 'between:1,7'], 'group' => 'attendance', 'label' => 'Working days (1 = Monday … 7 = Sunday)'],
            'checkin_attempts_per_minute' => ['type' => 'int', 'default' => 20, 'rules' => ['integer', 'min:3', 'max:600'], 'group' => 'attendance', 'label' => 'Check-in / check-out requests per minute per user'],
            'max_days_off_per_week' => ['type' => 'int', 'default' => 2, 'rules' => ['integer', 'min:0', 'max:6'], 'group' => 'attendance', 'label' => 'Maximum days off per week'],
            'max_remote_days_per_week' => ['type' => 'int', 'default' => 2, 'rules' => ['integer', 'min:0', 'max:7'], 'group' => 'attendance', 'label' => 'Maximum remote days per week'],

            // Reports
            'daily_report_time' => ['type' => 'time', 'default' => '17:00', 'rules' => ['date_format:H:i'], 'group' => 'reports', 'label' => 'Daily report generation time'],
            'weekly_window_day' => ['type' => 'int', 'default' => 5, 'rules' => ['integer', 'between:1,7'], 'group' => 'reports', 'label' => 'Weekly report day (1 = Monday … 7 = Sunday)'],
            'weekly_window_start' => ['type' => 'time', 'default' => '16:00', 'rules' => ['date_format:H:i'], 'group' => 'reports', 'label' => 'Weekly report window opens'],
            'weekly_window_end' => ['type' => 'time', 'default' => '17:00', 'rules' => ['date_format:H:i'], 'group' => 'reports', 'label' => 'Weekly report window closes'],
            'weekly_reminder_time' => ['type' => 'time', 'default' => '16:30', 'rules' => ['date_format:H:i'], 'group' => 'reports', 'label' => 'Weekly report reminder time'],
            'monthly_report_day' => ['type' => 'int', 'default' => 26, 'rules' => ['integer', 'between:1,28'], 'group' => 'reports', 'label' => 'Monthly report day of month'],
            'monthly_report_time' => ['type' => 'time', 'default' => '08:00', 'rules' => ['date_format:H:i'], 'group' => 'reports', 'label' => 'Monthly report sending time'],
            'monthly_report_recipient_ids' => ['type' => 'int_list', 'default' => [], 'rules' => ['array', 'max:50'], 'item_rules' => ['integer', 'distinct', 'min:1'], 'group' => 'reports', 'label' => 'Additional monthly report recipients (admin ids)'],
            'report_pdf_max_mb' => ['type' => 'int', 'default' => 10, 'rules' => ['integer', 'min:1', 'max:20'], 'group' => 'reports', 'label' => 'Maximum PDF report size (MB)'],
            'email_attachments_max_mb' => ['type' => 'int', 'default' => 20, 'rules' => ['integer', 'min:1', 'max:28'], 'group' => 'reports', 'label' => 'Maximum email attachments size (MB)'],
            'weekly_report_max_versions' => ['type' => 'int', 'default' => 10, 'rules' => ['integer', 'min:1', 'max:50'], 'group' => 'reports', 'label' => 'Maximum PDF versions an employee can upload for one week'],
            'weekly_absent_friday_grace' => ['type' => 'bool', 'default' => true, 'rules' => ['boolean'], 'group' => 'reports', 'label' => 'An employee expected on the report day but without check-in may also submit on the next working day without being late'],
            'report_catchup_days' => ['type' => 'int', 'default' => 7, 'rules' => ['integer', 'min:1', 'max:31'], 'group' => 'reports', 'label' => 'A missed report run is caught up for this many days (older ones are skipped)'],
            'tasks_max_per_day' => ['type' => 'int', 'default' => 100, 'rules' => ['integer', 'min:1', 'max:1000'], 'group' => 'reports', 'label' => 'Maximum tasks per employee per day'],
            'clients_max_per_day' => ['type' => 'int', 'default' => 200, 'rules' => ['integer', 'min:1', 'max:2000'], 'group' => 'reports', 'label' => 'Maximum client entries per employee per day'],
            'client_quantity_max' => ['type' => 'int', 'default' => 999, 'rules' => ['integer', 'min:1', 'max:100000'], 'group' => 'reports', 'label' => 'Maximum quantity of one client entry'],
            'dashboard_top_services' => ['type' => 'int', 'default' => 5, 'rules' => ['integer', 'min:1', 'max:50'], 'group' => 'reports', 'label' => 'Number of top client services shown on the admin dashboard'],

            // System
            'timezone' => ['type' => 'string', 'default' => 'Africa/Accra', 'rules' => ['timezone'], 'group' => 'system', 'label' => 'Time zone', 'editable' => false],
            'scheduled_run_stale_minutes' => ['type' => 'int', 'default' => 15, 'rules' => ['integer', 'min:1', 'max:1440'], 'group' => 'system', 'label' => 'A scheduled task still running after this many minutes is considered crashed and retried'],
            'log_retention_months' => ['type' => 'int', 'default' => 12, 'rules' => ['integer', 'min:12', 'max:120'], 'group' => 'system', 'label' => 'Log retention (months)'],
            'photo_max_kb' => ['type' => 'int', 'default' => 5120, 'rules' => ['integer', 'min:100', 'max:20480'], 'group' => 'system', 'label' => 'Maximum profile photo size (KB)'],
            'photo_max_dimension_px' => ['type' => 'int', 'default' => 1024, 'rules' => ['integer', 'min:128', 'max:4096'], 'group' => 'system', 'label' => 'Profile photos are resized to at most this width/height (px)'],
            'file_url_ttl_minutes' => ['type' => 'int', 'default' => 10, 'rules' => ['integer', 'min:1', 'max:120'], 'group' => 'system', 'label' => 'Temporary file link lifetime (minutes)'],

            // Security
            'lockout_failures_per_series' => ['type' => 'int', 'default' => 5, 'rules' => ['integer', 'min:3', 'max:20'], 'group' => 'security', 'label' => 'Failed logins per series'],
            'lockout_block_minutes' => ['type' => 'int_list', 'default' => [10, 20], 'rules' => ['array', 'min:1', 'max:5'], 'group' => 'security', 'label' => 'Block duration after each series (minutes); the next series locks'],
            'lockout_multi_device_threshold' => ['type' => 'int', 'default' => 2, 'rules' => ['integer', 'min:2', 'max:10'], 'group' => 'security', 'label' => 'Locked device buckets that lock the whole account'],
            'ip_login_failures_limit' => ['type' => 'int', 'default' => 60, 'rules' => ['integer', 'min:5', 'max:1000'], 'group' => 'security', 'label' => 'Sign-in attempts from unknown devices per IP per window (shared office IP: known devices are not counted)'],
            'ip_login_window_minutes' => ['type' => 'int', 'default' => 15, 'rules' => ['integer', 'min:1', 'max:1440'], 'group' => 'security', 'label' => 'Per-IP window (minutes)'],
            'identification_code_ttl_hours' => ['type' => 'int', 'default' => 48, 'rules' => ['integer', 'min:1', 'max:720'], 'group' => 'security', 'label' => 'Identification code validity (hours)'],
            'identification_code_length' => ['type' => 'int', 'default' => 10, 'rules' => ['integer', 'min:8', 'max:20'], 'group' => 'security', 'label' => 'Identification code length'],
            'otp_ttl_minutes' => ['type' => 'int', 'default' => 10, 'rules' => ['integer', 'min:1', 'max:60'], 'group' => 'security', 'label' => 'Super admin login code validity (minutes)'],
            'otp_length' => ['type' => 'int', 'default' => 8, 'rules' => ['integer', 'min:6', 'max:12'], 'group' => 'security', 'label' => 'Super admin login code length'],
            'otp_requests_per_hour' => ['type' => 'int', 'default' => 5, 'rules' => ['integer', 'min:1', 'max:60'], 'group' => 'security', 'label' => 'Login code requests per hour (per account and IP)'],
            'dashboard_requests_per_minute' => ['type' => 'int', 'default' => 30, 'rules' => ['integer', 'min:5', 'max:600'], 'group' => 'security', 'label' => 'Dashboard requests per user per minute'],
            'log_export_per_hour' => ['type' => 'int', 'default' => 10, 'rules' => ['integer', 'min:1', 'max:100'], 'group' => 'security', 'label' => 'Activity log CSV exports per super admin per hour'],
            'log_export_max_rows' => ['type' => 'int', 'default' => 100000, 'rules' => ['integer', 'min:100', 'max:1000000'], 'group' => 'security', 'label' => 'Maximum rows in one activity log CSV export'],
            'otp_max_active_codes' => ['type' => 'int', 'default' => 3, 'rules' => ['integer', 'min:1', 'max:10'], 'group' => 'security', 'label' => 'Super admin login codes valid at the same time'],
            'recovery_link_ttl_minutes' => ['type' => 'int', 'default' => 30, 'rules' => ['integer', 'min:5', 'max:1440'], 'group' => 'security', 'label' => 'Recovery link validity (minutes)'],
            'email_change_ttl_minutes' => ['type' => 'int', 'default' => 60, 'rules' => ['integer', 'min:5', 'max:1440'], 'group' => 'security', 'label' => 'Email change link validity (minutes)'],
            'email_change_requests_per_hour' => ['type' => 'int', 'default' => 3, 'rules' => ['integer', 'min:1', 'max:20'], 'group' => 'security', 'label' => 'Email change requests per hour per user'],
            'logs_admins_see_super_admin_events' => ['type' => 'bool', 'default' => false, 'rules' => ['boolean'], 'group' => 'security', 'label' => 'Admins with log access also see super admin events'],
            'password_min_length' => ['type' => 'int', 'default' => 10, 'rules' => ['integer', 'min:8', 'max:64'], 'group' => 'security', 'label' => 'Minimum password length'],
            'session_idle_days' => ['type' => 'int', 'default' => 30, 'rules' => ['integer', 'min:1', 'max:365'], 'group' => 'security', 'label' => 'Device session expires after inactivity (days)'],
            'signup_requests_per_hour' => ['type' => 'int', 'default' => 50, 'rules' => ['integer', 'min:1', 'max:1000'], 'group' => 'security', 'label' => 'Sign-up requests per hour per IP (the whole office shares one IP)'],
            'signup_requests_per_email_per_hour' => ['type' => 'int', 'default' => 3, 'rules' => ['integer', 'min:1', 'max:20'], 'group' => 'security', 'label' => 'Sign-up requests per hour per email'],
            'signup_requests_global_per_hour' => ['type' => 'int', 'default' => 200, 'rules' => ['integer', 'min:1', 'max:10000'], 'group' => 'security', 'label' => 'Sign-up requests per hour in total, all IPs together (flood protection)'],
            'signup_alert_emails_per_hour' => ['type' => 'int', 'default' => 5, 'rules' => ['integer', 'min:0', 'max:100'], 'group' => 'security', 'label' => 'Sign-up alert emails per hour to approvers (beyond: one summary email, then in-app notifications only)'],
            'pending_signup_retention_days' => ['type' => 'int', 'default' => 30, 'rules' => ['integer', 'min:1', 'max:365'], 'group' => 'security', 'label' => 'Unanswered sign-up requests are removed after (days)'],
            'recovery_links_sent_per_hour' => ['type' => 'int', 'default' => 3, 'rules' => ['integer', 'min:1', 'max:20'], 'group' => 'security', 'label' => 'Recovery links emailed per super admin per hour (a known device is never refused)'],
            'super_admin_promotion_requires_tier' => ['type' => 'bool', 'default' => true, 'rules' => ['boolean'], 'group' => 'security', 'label' => 'Only an admin holding "Eligible for promotion to super admin" can be made super admin'],
            'api_requests_per_minute' => ['type' => 'int', 'default' => 120, 'rules' => ['integer', 'min:30', 'max:10000'], 'group' => 'security', 'label' => 'API requests per minute per user/IP'],
        ];
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::all());
    }
}
