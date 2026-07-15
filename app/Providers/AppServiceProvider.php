<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        // The proxy makes outbound HTTP requests on the caller's behalf and
        // is open to guests, so keep the anonymous limit tight.
        RateLimiter::for('proxy', function (Request $request) {
            return $request->user()
                ? Limit::perMinute(120)->by('proxy:user:'.$request->user()->id)
                : Limit::perMinute(20)->by('proxy:ip:'.$request->ip());
        });

        // MCP/A2A test endpoints are auth-only; limit per user.
        RateLimiter::for('outbound-test', function (Request $request) {
            return Limit::perMinute(60)->by('outbound:user:'.($request->user()?->id ?: $request->ip()));
        });

        // Credential endpoints: slow down brute-force attempts per IP.
        RateLimiter::for('auth-attempts', function (Request $request) {
            return Limit::perMinute(10)->by('auth:ip:'.$request->ip());
        });
    }
}
