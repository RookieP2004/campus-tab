<?php

namespace App\Modules\Communication;

use App\Modules\Communication\Events\NotificationCreated;
use App\Modules\Identity\Models\User;
use App\Providers\AppServiceProvider;
use App\Support\Push\ArrayPushSender;
use App\Support\Push\LogPushSender;
use App\Support\Push\NullPushSender;
use App\Support\Push\PushSender;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Notices and notifications: the push driver, the Reverb channel auth
 * route, and the one private channel per signed-in user.
 */
class CommunicationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PushSender::class, function (): PushSender {
            $driver = (string) (config('communication.push_driver') ?: ($this->app->environment('local', 'testing') ? 'log' : 'none'));

            if (in_array($driver, AppServiceProvider::DEV_SMS_DRIVERS, true) && ! $this->app->environment('local', 'testing')) {
                throw new RuntimeException("PUSH_DRIVER [{$driver}] is allowed only in local and testing.");
            }

            return match ($driver) {
                'log' => new LogPushSender,
                'array' => new ArrayPushSender,
                'none' => new NullPushSender,
                default => throw new RuntimeException("Unknown PUSH_DRIVER [{$driver}]."),
            };
        });
    }

    public function boot(): void
    {
        // POST /api/v1/broadcasting/auth: the web app's socket asks here (as a
        // background request) before joining its own private channel.
        Broadcast::routes(['prefix' => 'api/v1', 'middleware' => ['api', 'account']]);

        // school.<tenant>.user.<user>: only that signed-in user.
        Broadcast::channel(str_replace(['<t>', '<u>'], ['{tenantId}', '{userId}'], NotificationCreated::channel('<t>', '<u>')), function ($user, string $tenantId, string $userId): bool {
            return $user instanceof User && $user->tenant_id === $tenantId && (string) $user->getKey() === $userId;
        });
    }
}
