<?php

namespace App\Providers;

use App\Integrations\StaffIdentityProvider;
use App\Integrations\UnconfiguredStaffIdentityProvider;
use App\Models\User;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(StaffIdentityProvider::class, UnconfiguredStaffIdentityProvider::class);
    }

    public function boot(): void
    {
        foreach (config('feedback.permissions') as $permission) {
            Gate::define($permission, fn (User $user) => $user->active && in_array($permission, $user->permissions ?? [], true));
        }
        RateLimiter::for('internal', fn ($r) => Limit::perMinute(600)->by($r->ip()));
        FilamentTimezone::set('Asia/Vientiane');
    }
}
