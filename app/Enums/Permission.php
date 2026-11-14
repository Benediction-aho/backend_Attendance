<?php

namespace App\Enums;

/**
 * Admin permission tiers (SPECS section 2 and 9 bis). Super admins implicitly hold all of them.
 */
enum Permission: string
{
    case ViewPerformance = 'view_performance';
    case ViewLateness = 'view_lateness';
    case ViewClients = 'view_clients';
    case ViewTasks = 'view_tasks';
    case ApproveMissionCheckins = 'approve_mission_checkins';
    case ApproveFaceIdDevices = 'approve_faceid_devices';
    case CheckInForEmployee = 'checkin_for_employee';
    case ManageLeaves = 'manage_leaves';
    case ManageWorkSchedules = 'manage_work_schedules';
    case CreateUsers = 'create_users';
    case ApproveUsers = 'approve_users';
    case BlockUsers = 'block_users';
    case DeleteUsers = 'delete_users';
    case ViewReports = 'view_reports';
    case ViewActivities = 'view_activities';
    case ViewStatistics = 'view_statistics';
    case ReceiveWeeklyReports = 'receive_weekly_reports';
    case PromoteToSuperAdmin = 'promote_to_super_admin';
    case ViewLogs = 'view_logs';
    case ManageSettingsAttendance = 'manage_settings_attendance';
    case ManageSettingsReports = 'manage_settings_reports';
    case ManageSettingsSecurity = 'manage_settings_security';

    /** Permission that lets an admin manage one settings section. */
    public static function forSettingsSection(string $section): self
    {
        return match ($section) {
            'attendance' => self::ManageSettingsAttendance,
            'reports' => self::ManageSettingsReports,
            default => self::ManageSettingsSecurity,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::ViewPerformance => 'View performance',
            self::ViewLateness => 'View lateness',
            self::ViewClients => 'View clients brought by each employee',
            self::ViewTasks => 'View completed tasks',
            self::ApproveMissionCheckins => 'Approve mission check-ins',
            self::ApproveFaceIdDevices => 'Approve new Face ID devices',
            self::CheckInForEmployee => 'Check in on behalf of an employee (fallback)',
            self::ManageLeaves => 'Manage employee leave',
            self::ManageWorkSchedules => 'Edit employee days off and remote days',
            self::CreateUsers => 'Add a user directly',
            self::ApproveUsers => 'Approve accounts and send identification codes',
            self::BlockUsers => 'Block, unblock and unlock users',
            self::DeleteUsers => 'Delete users',
            self::ViewReports => 'View reports (daily, weekly, PDF)',
            self::ViewActivities => 'View daily activities',
            self::ViewStatistics => 'View statistics and analytics',
            self::ReceiveWeeklyReports => 'Receive weekly report notifications and emails',
            self::PromoteToSuperAdmin => 'Eligible for promotion to super admin',
            self::ViewLogs => 'View activity logs',
            self::ManageSettingsAttendance => 'Settings – Attendance',
            self::ManageSettingsReports => 'Settings – Reports',
            self::ManageSettingsSecurity => 'Settings – Security',
        };
    }
}
