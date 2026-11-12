<?php

namespace App\Domain\Reports;

use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\User;
use App\Settings\SettingsService;
use Illuminate\Support\Collection;

/** Who receives report notifications and emails (SPECS 7 and 8). */
class ReportRecipients
{
    public function __construct(private readonly SettingsService $settings) {}

    /**
     * Weekly reports: super admins + admins holding receive_weekly_reports (active accounts only).
     *
     * @return Collection<int, User>
     */
    public function weekly(): Collection
    {
        return User::query()->holdingPermission(Permission::ReceiveWeeklyReports)->orderBy('id')->get();
    }

    /**
     * Monthly report: super admins, always, + the admins selected in monthly_report_recipient_ids.
     *
     * @return Collection<int, User>
     */
    public function monthly(): Collection
    {
        $ids = $this->settings->intList('monthly_report_recipient_ids');

        return User::query()->where('status', UserStatus::Active->value)
            ->where(fn ($q) => $q->where('role', Role::SuperAdmin->value)
                ->orWhere(fn ($q) => $q->whereIn('role', [Role::Admin->value])->whereIn('id', $ids === [] ? [0] : $ids)))
            ->orderBy('id')->get();
    }
}
