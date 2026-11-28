<?php

namespace App\Modules\Communication\Listeners;

use App\Modules\Communication\Models\PushDevice;
use App\Modules\Identity\Events\DeviceSessionEnded;
use App\Support\Tenancy\TenantContext;

/**
 * Push keeps going after the app is closed (the owner's choice: the token
 * lives in memory only, so closing signs out, but the device stays
 * registered for the generic texts). It stops when the person signs out on
 * purpose, the device is signed out remotely, the password or MFA is reset,
 * or the account is deactivated. Account-wide reasons forget every device
 * of that person.
 */
class ForgetPushDevices
{
    private const ACCOUNT_WIDE = ['password_changed', 'deactivated', 'mfa_reset', 'mfa_reset_by_platform'];

    public function __construct(private readonly TenantContext $tenant) {}

    public function handle(DeviceSessionEnded $event): void
    {
        if (! in_array($event->reason, (array) config('communication.push.forget_on'), true)) {
            return;
        }

        $this->tenant->run($event->tenantId, function () use ($event): void {
            $query = PushDevice::query()->where('user_id', $event->userId);
            if (! in_array($event->reason, self::ACCOUNT_WIDE, true)) {
                $query->where('device_session_id', $event->deviceSessionId);
            }
            $query->delete();
        });
    }
}
