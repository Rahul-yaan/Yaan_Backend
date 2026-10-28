<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Enforce HTTPS links in production environment
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Configure brute-force rate limiter for authentication endpoints:
        // 5 requests per minute per account/endpoint combination,
        // and 60 requests per minute per IP address.
        RateLimiter::for('auth', function (Request $request) {
            $identifier = strtolower(trim((string) (
                $request->input('phone')
                ?? $request->input('email')
                ?? $request->input('login_id')
                ?? $request->input('user_id')
                ?? ''
            )));

            return [
                Limit::perMinute(5)->by($identifier . '|' . $request->path()),
                Limit::perMinute(60)->by($request->ip()),
            ];
        });
    }
}
