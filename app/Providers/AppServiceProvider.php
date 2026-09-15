<?php

namespace App\Providers;

use App\Services\Image\AdvancedBackgroundRemovalEngine;
use App\Services\Image\BackgroundRemovalEngineInterface;
use App\Services\Image\ColorSamplingBackgroundRemovalEngine;
use App\Services\Image\HybridBackgroundRemovalEngine;
use App\Services\Image\ModnetBackgroundRemovalEngine;
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
        $this->app->bind(BackgroundRemovalEngineInterface::class, function ($app) {
            $engineKey = config('fileprocessor.background_removal.engine', 'hybrid');
            $engineMap = config('fileprocessor.background_removal.engines', [\App\Services\Image\HybridBackgroundRemovalEngine::class,
                'ml_modnet'      => ModnetBackgroundRemovalEngine::class,
                'advanced'       => AdvancedBackgroundRemovalEngine::class,
                'color_sampling' => ColorSamplingBackgroundRemovalEngine::class,
            ]);

            $engineClass = $engineMap[$engineKey] ?? HybridBackgroundRemovalEngine::class;

            return $app->make($engineClass);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Enforce HTTPS in production or when APP_URL is configured with https
        if (str_starts_with((string) config('app.url'), 'https://') || $this->app->environment('production')) {
            URL::forceScheme('https');
        }

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
            return Limit::perMinute($limit)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('share-download', function (Request $request) {
            $limit = config('fileprocessor.rate_limits.downloads_per_min', 60);
            return Limit::perMinute($limit)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('public-share-create', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip());
        });

        RateLimiter::for('public-share-access', function (Request $request) {
            return Limit::perMinute(60)->by($request->ip());
        });
    }
}
