<?php

namespace App\Providers;

use App\Support\Sms\ArraySmsSender;
use App\Support\Sms\LogSmsSender;
use App\Support\Sms\SmsSender;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /** SMS drivers that only write locally; never allowed outside development and tests. */
    public const DEV_SMS_DRIVERS = ['log', 'array'];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SmsSender::class, fn () => match (self::smsDriver()) {
            'log' => new LogSmsSender,
            'array' => new ArraySmsSender,
            default => throw new RuntimeException('Unknown SMS_DRIVER ['.self::smsDriver().'].'),
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Outside development and tests a missing or local-only SMS driver is a
        // configuration error, raised at start-up: never a silent fallback.
        if (! $this->app->environment('local', 'testing')) {
            self::smsDriver();
        }

        // Public branding for the sign-in screen.
        RateLimiter::for('public', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));

        // Licence activation: strict, since it is unauthenticated and sends OTPs.
        RateLimiter::for('activation', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
    }

    /**
     * The SMS driver to use. In local and testing an unset driver means
     * `log`; anywhere else it must be set, and `log`/`array` are refused.
     */
    public static function smsDriver(): string
    {
        $driver = config('services.sms.driver');
        $local = app()->environment('local', 'testing');

        if (! is_string($driver) || $driver === '') {
            if ($local) {
                return 'log';
            }

            throw new RuntimeException('SMS_DRIVER is not set. Configure a real SMS gateway; text messages are never silently dropped or logged in '.app()->environment().'.');
        }

        if (in_array($driver, self::DEV_SMS_DRIVERS, true) && ! $local) {
            throw new RuntimeException("SMS_DRIVER [{$driver}] is allowed only in local and testing. Configure a real SMS gateway for ".app()->environment().'.');
        }

        return $driver;
    }
}
