<?php

namespace App\Providers;

use App\Domain\Attendance\CheckinAssertionVerifier;
use App\Domain\FaceId\WebAuthnAssertionVerifier;
use App\Enums\Permission;
use App\Models\User;
use App\Services\Push\PushTransport;
use App\Services\Push\WebPushTransport;
use App\Settings\SettingsService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Scoped: fresh per request / queued job, so long-running workers never keep stale values.
        $this->app->scoped(SettingsService::class);
        $this->app->bind(CheckinAssertionVerifier::class, WebAuthnAssertionVerifier::class);
        $this->app->bind(PushTransport::class, WebPushTransport::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Numeric ids only (anything else is a plain 404, never a database error).
        Route::pattern('user', '[0-9]{1,18}');

        // Every API route is rate limited per user (or per IP for guests); values come from settings.
        RateLimiter::for('api', function (Request $request) {
            $user = $request->user('sanctum');

            return Limit::perMinute(app(SettingsService::class)->int('api_requests_per_minute'))
                ->by($user instanceof User ? 'user:'.$user->id : 'ip:'.$request->ip());
        });
        RateLimiter::for('attendance', fn (Request $request) => Limit::perMinute(app(SettingsService::class)->int('checkin_attempts_per_minute'))
            ->by('attendance:'.($request->user('sanctum')?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('client-errors', fn (Request $request) => Limit::perMinute(10)->by('client-errors:'.($request->user('sanctum')?->getAuthIdentifier() ?? $request->ip())));
        // P6-05: dashboards are polled; a dedicated per-user limit keeps them from saturating workers.
        RateLimiter::for('dashboard', fn (Request $request) => Limit::perMinute(app(SettingsService::class)->int('dashboard_requests_per_minute'))
            ->by('dashboard:'.($request->user('sanctum')?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('log-export', fn (Request $request) => Limit::perHour(app(SettingsService::class)->int('log_export_per_hour'))
            ->by('log-export:'.($request->user('sanctum')?->getAuthIdentifier() ?? $request->ip()))
            // UX6-06: say when the next export is possible.
            ->response(function (Request $request, array $headers) {
                $seconds = max(1, (int) ($headers['Retry-After'] ?? 60));
                $minutes = (int) ceil($seconds / 60);

                return response()->json([
                    'message' => "You have reached the hourly limit of CSV exports. Try again in {$minutes} minute".($minutes > 1 ? 's' : '').'.',
                    'code' => 'too_many_exports',
                    'retry_after_seconds' => $seconds,
                ], 429, $headers);
            }));
        // Sliding device sessions (FIABILITE B3): a token unused for longer than the idle period stops working.
        // Checked before Sanctum refreshes last_used_at.
        Sanctum::authenticateAccessTokensUsing(function (PersonalAccessToken $token, bool $isValid): bool {
            if (! $isValid) {
                return false;
            }
            $lastUsed = $token->last_used_at ?? $token->created_at;
            if ($lastUsed !== null && $lastUsed->lt(Carbon::now()->subDays(app(SettingsService::class)->int('session_idle_days')))) {
                $token->delete();

                return false;
            }

            return true;
        });

        Gate::define('manage-settings', fn (User $user) => $user->settingsSections() !== []);
        Gate::define('super-admin', fn (User $user) => $user->isSuperAdmin());
        Gate::define('view-logs', fn (User $user) => $user->hasPermission(Permission::ViewLogs));
        // Attendance views (any attendance-related tier); field-level filtering in AttendancePresenter.
        Gate::define('view-attendance', fn (User $user) => $user->isSuperAdmin() || collect([
            Permission::ViewLateness, Permission::ViewActivities, Permission::ViewPerformance, Permission::ApproveMissionCheckins,
            Permission::CheckInForEmployee, Permission::ManageLeaves, Permission::ManageWorkSchedules,
        ])->contains(fn (Permission $p) => $user->hasPermission($p)));
        Gate::define('manage-holidays', fn (User $user) => $user->hasPermission(Permission::ManageLeaves));
        Gate::define('permission', fn (User $user, string $permission) => $user->hasPermission(Permission::from($permission)));
    }
}
