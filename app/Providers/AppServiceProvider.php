<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Telescope\Telescope;
use Laravel\Pulse\Facades\Pulse;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register Telescope only in non-production environments
        if ($this->app->environment('local', 'development', 'staging')) {
            $this->app->register(\Laravel\Telescope\TelescopeServiceProvider::class);
        }
        
        // Register Pulse service provider
        $this->app->register(\Laravel\Pulse\PulseServiceProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Configure Telescope access
        Telescope::auth(function ($request) {
            // Allow access in local/dev environment
            if (app()->environment('local', 'development')) {
                return true;
            }
            
            // In production/staging, require authentication
            return $request->user() && $request->user()->hasRole('admin');
        });
        
        // Configure Pulse access
        Pulse::user(fn ($user) => [
            'name' => $user->name,
            'extra' => $user->email,
            'avatar' => null,
        ]);
    }
}
