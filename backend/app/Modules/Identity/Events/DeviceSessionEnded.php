<?php

namespace App\Modules\Identity\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A signed-in device ended (signed out, revoked remotely, timed out).
 * Communication listens to stop push to that device when the reason calls
 * for it (communication.push.forget_on).
 */
class DeviceSessionEnded
{
    use Dispatchable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $userId,
        public readonly string $deviceSessionId,
        public readonly string $reason,
    ) {}
}
