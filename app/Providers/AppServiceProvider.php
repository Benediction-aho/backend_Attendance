<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Models\User;
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
        RateLimiter::for('client-errors', fn (Request $request) => Limit::perMinute(10)->by('client-errors:'.($request->user('sanctum')?->getAuthIdentifier() ?? $request->ip())));
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

        Gate::define('super-admin', fn (User $user) => $user->isSuperAdmin());
        Gate::define('view-logs', fn (User $user) => $user->hasPermission(Permission::ViewLogs));
        Gate::define('permission', fn (User $user, string $permission) => $user->hasPermission(Permission::from($permission)));
    }
}
