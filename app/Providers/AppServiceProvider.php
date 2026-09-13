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
        $this->configureRateLimiting();
    }

    protected function configureRateLimiting(): void
    {
        RateLimiter::for('tool-processing', function (Request $request) {
            $limit = config('fileprocessor.rate_limits.global_per_minute', 30);
            return Limit::perMinute($limit)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('heavy-processing', function (Request $request) {
            $limit = config('fileprocessor.rate_limits.heavy_per_minute', 10);
            return Limit::perMinute($limit)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('file-download', function (Request $request) {
            $limit = config('fileprocessor.rate_limits.downloads_per_min', 60);
            return Limit::perMinute($limit)->by($request->ip());
        });
    }
}
