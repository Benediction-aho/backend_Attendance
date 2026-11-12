<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Models\User;
use App\Settings\SettingsService;
use Illuminate\Support\Facades\Gate;
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
        Gate::define('super-admin', fn (User $user) => $user->isSuperAdmin());
        Gate::define('permission', fn (User $user, string $permission) => $user->hasPermission(Permission::from($permission)));
    }
}
