<?php

namespace App\Providers;

use App\Support\Sms\ArraySmsSender;
use App\Support\Sms\LogSmsSender;
use App\Support\Sms\SmsSender;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SmsSender::class, fn () => match (config('services.sms.driver')) {
            'log' => new LogSmsSender,
            'array' => new ArraySmsSender,
            default => throw new InvalidArgumentException('Unknown SMS_DRIVER ['.config('services.sms.driver').'].'),
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Public branding for the sign-in screen.
        RateLimiter::for('public', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));

        // Licence activation: strict, since it is unauthenticated and sends OTPs.
        RateLimiter::for('activation', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
    }
}
