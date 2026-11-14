<?php

namespace App\Domain\Attendance;

use App\Enums\Permission;
use App\Models\Attendance;
use App\Models\User;
use App\Settings\SettingsService;

/**
 * Only the fields the caller may see (security.md: authorization on the server, minimal data).
 * Raw coordinates are never returned except to super admins; others get the distance to the office.
 */
class AttendancePresenter
{
    public function __construct(private readonly SettingsService $settings) {}

    /** @return array<string, mixed> */
    public function forEmployee(Attendance $a): array
    {
        $radius = $this->settings->int('checkin_radius_m');

        return [
            'id' => $a->id,
            'work_date' => $a->work_date->toDateString(),
            'day_status' => $a->day_status,
            'check_in_type' => $a->check_in_type,
            'check_in_at' => $a->check_in_at->toIso8601String(),
            'check_in_distance_m' => $this->num($a->check_in_distance_m),
            'is_late' => $a->is_late,
            'late_after' => $a->late_after,
            'faceid_verified' => $a->faceid_verified,
            'reason' => $a->reason,
            'mission_status' => $a->mission_status,
            'check_out_at' => $a->check_out_at?->toIso8601String(),
            'check_out_type' => $a->check_out_type,
            'checkout_forgotten' => $a->check_out_type === Attendance::CHECKOUT_AUTO,
            'check_out_distance_m' => $this->num($a->check_out_distance_m),
            'check_out_position_available' => $a->check_out_at !== null ? $a->check_out_distance_m !== null : null,
            'check_out_at_office' => $a->check_out_distance_m !== null ? (float) $a->check_out_distance_m <= $radius : null,
            'early_leave' => $a->early_leave,
            'early_leave_reason' => $a->early_leave_reason,
        ];
    }

    /** @return array<string, mixed> */
    public function forAdmin(Attendance $a, User $viewer): array
    {
        $out = $this->forEmployee($a);
        $out['user_id'] = $a->user_id;
        $out['checked_in_by'] = $a->checked_in_by;
        $out['mission_validated_by'] = $a->mission_validated_by;
        $out['mission_validated_at'] = $a->mission_validated_at?->toIso8601String();
        $out['mission_note'] = $a->mission_note;

        if (! $viewer->hasPermission(Permission::ViewLateness)) {
            unset($out['is_late'], $out['late_after'], $out['early_leave'], $out['early_leave_reason']);
        }
        if ($viewer->isSuperAdmin()) {
            $out['check_in_position'] = $a->check_in_lat !== null ? ['lat' => (float) $a->check_in_lat, 'lng' => (float) $a->check_in_lng, 'accuracy_m' => $this->num($a->check_in_accuracy_m)] : null;
            $out['check_out_position'] = $a->check_out_lat !== null ? ['lat' => (float) $a->check_out_lat, 'lng' => (float) $a->check_out_lng, 'accuracy_m' => $this->num($a->check_out_accuracy_m)] : null;
        }

        return $out;
    }

    private function num(?string $v): ?float
    {
        return $v === null ? null : round((float) $v, 1);
    }
}
