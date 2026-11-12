<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Models\User;
use App\Settings\SettingsService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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
        // Every API route is rate limited per user (or per IP for guests); values come from settings.
        RateLimiter::for('api', function (Request $request) {
            $user = $request->user('sanctum');

            return Limit::perMinute(app(SettingsService::class)->int('api_requests_per_minute'))
                ->by($user instanceof User ? 'user:'.$user->id : 'ip:'.$request->ip());
        });
        RateLimiter::for('signup', fn (Request $request) => Limit::perHour(app(SettingsService::class)->int('signup_requests_per_hour'))
            ->by('signup:'.$request->ip())
            ->response(fn () => response()->json(['message' => 'Too many requests. Please try again later.'], 429)));

        Gate::define('super-admin', fn (User $user) => $user->isSuperAdmin());
        Gate::define('permission', fn (User $user, string $permission) => $user->hasPermission(Permission::from($permission)));
    }
}
