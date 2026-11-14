<?php

use App\Models\SettingHistory;
use App\Models\User;
use App\Settings\SettingDefinitions;
use App\Settings\SettingsService;
use Illuminate\Support\Carbon;

beforeEach(fn () => $this->settings = app(SettingsService::class));

it('returns the SPECS defaults when nothing is stored', function () {
    expect($this->settings->int('checkin_radius_m'))->toBe(50)
        ->and($this->settings->string('late_after'))->toBe('08:10')
        ->and($this->settings->string('checkin_reminder_time'))->toBe('08:05')
        ->and($this->settings->bool('faceid_required_for_checkin'))->toBeTrue()
        ->and($this->settings->get('office_latitude'))->toBe(5.595223)
        ->and($this->settings->intList('lockout_block_minutes'))->toBe([10, 20])
        ->and($this->settings->int('lockout_failures_per_series'))->toBe(5)
        ->and($this->settings->int('identification_code_ttl_hours'))->toBe(48)
        ->and($this->settings->int('otp_ttl_minutes'))->toBe(10)
        ->and($this->settings->string('timezone'))->toBe('Africa/Accra');
});

it('applies a new value only from its effective date and never rewrites the past', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-10 09:00', 'Africa/Accra'));
    $this->settings->set(['late_after' => '08:30'], null, Carbon::parse('2026-11-12 00:00', 'Africa/Accra'));

    expect($this->settings->string('late_after'))->toBe('08:10')
        ->and($this->settings->string('late_after', Carbon::parse('2026-11-11 23:59', 'Africa/Accra')))->toBe('08:10')
        ->and($this->settings->string('late_after', Carbon::parse('2026-11-12 00:00', 'Africa/Accra')))->toBe('08:30');

    Carbon::setTestNow(Carbon::parse('2026-11-12 08:00', 'Africa/Accra'));
    app()->forgetScopedInstances();
    expect(app(SettingsService::class)->string('late_after'))->toBe('08:30');
});

it('records history with old and new values and the author', function () {
    $admin = User::factory()->superAdmin()->create();
    $this->settings->set(['checkin_radius_m' => 80], $admin);
    $this->settings->set(['checkin_radius_m' => 60], $admin);

    $history = SettingHistory::query()->orderBy('id')->get();
    expect($history)->toHaveCount(2)
        ->and($history[0]->old_value)->toBe(50)
        ->and($history[0]->new_value)->toBe(80)
        ->and($history[1]->old_value)->toBe(80)
        ->and($history[1]->changed_by)->toBe($admin->id)
        ->and($this->settings->int('checkin_radius_m'))->toBe(60);
});

it('rejects unknown keys', function () {
    $this->settings->get('nope');
})->throws(InvalidArgumentException::class);

it('has a default for every business value listed in SPECS section 3', function () {
    foreach (['office_latitude', 'office_longitude', 'checkin_radius_m', 'max_days_off_per_week', 'report_pdf_max_mb', 'email_attachments_max_mb', 'log_retention_months', 'late_after', 'checkin_reminder_time', 'faceid_required_for_checkin', 'end_of_day_time', 'auto_checkout_time', 'track_work_hours', 'monthly_report_time', 'weekly_reminder_time', 'early_checkout_reason_required', 'daily_report_time', 'weekly_window_day', 'weekly_window_start', 'weekly_window_end', 'monthly_report_day', 'monthly_report_recipient_ids', 'timezone'] as $key) {
        expect(SettingDefinitions::has($key))->toBeTrue("missing $key");
    }
});
