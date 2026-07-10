<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Numeric environment variables consumed by config/filestorage.php,
     * mapped to their documented default values.
     */
    private const NUMERIC_ENV_DEFAULTS = [
        'MAX_UPLOAD_BYTES'    => 10_485_760,
        'RETENTION_HOURS'     => 24,
        'RATE_LIMIT_UPLOAD'   => 20,
        'RATE_LIMIT_DELETE'   => 30,
        'RATE_LIMIT_DOWNLOAD' => 60,
        'PAGINATION_PER_PAGE' => 15,
    ];

    /**
     * Register any application services.
     *
     * Loads the formatFileSize() global helper so it is available
     * in Blade templates and anywhere else in the application.
     *
     * Requirements: 2.3
     */
    public function register(): void
    {
        require_once app_path('Helpers/FileHelpers.php');
    }

    /**
     * Bootstrap any application services.
     *
     * Validates that every numeric environment variable consumed by
     * config/filestorage.php is present and contains a numeric value.
     * Emits a WARNING log entry — identifying the variable name and the
     * default that was applied — whenever a variable is absent or
     * non-numeric.
     *
     * Also registers custom rate limiters for upload, delete, and download
     * endpoints per config('filestorage.rate_limit_*').
     *
     * Requirements: 8.4, 11.5, 11.6, 12.6
     */
    public function boot(): void
    {
        foreach (self::NUMERIC_ENV_DEFAULTS as $envKey => $default) {
            $raw = $_ENV[$envKey] ?? getenv($envKey);

            if ($raw === false || $raw === null || $raw === '') {
                logger()->warning(
                    "Configuration warning: environment variable '{$envKey}' is not set. " .
                    "Applying default value: {$default}."
                );
            } elseif (! is_numeric($raw)) {
                logger()->warning(
                    "Configuration warning: environment variable '{$envKey}' has a non-numeric " .
                    "value (" . json_encode($raw) . "). Applying default value: {$default}."
                );
            }
        }

        // Register custom throttle limiters driven by config/filestorage.php
        RateLimiter::for('upload', fn (Request $request) => Limit::perMinute(config('filestorage.rate_limit_upload'))->by($request->ip()));
        RateLimiter::for('delete', fn (Request $request) => Limit::perMinute(config('filestorage.rate_limit_delete'))->by($request->ip()));
        RateLimiter::for('download', fn (Request $request) => Limit::perMinute(config('filestorage.rate_limit_download'))->by($request->ip()));
    }
}
