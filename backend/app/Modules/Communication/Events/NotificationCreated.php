<?php

namespace App\Modules\Communication\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Tells one signed-in user's open screens that something new arrived, over
 * Reverb, on their own private channel school.<tenant>.user.<user>. It
 * carries only the id, the type and the unread count: the screen then
 * fetches the notification (as a background request), so nothing readable
 * travels over the socket.
 */
class NotificationCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $userId,
        public readonly string $notificationId,
        public readonly string $type,
        public readonly int $unread,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel(self::channel($this->tenantId, $this->userId));
    }

    public function broadcastAs(): string
    {
        return 'notification.created';
    }

    /**
     * @return array{id: string, type: string, unread: int}
     */
    public function broadcastWith(): array
    {
        return ['id' => $this->notificationId, 'type' => $this->type, 'unread' => $this->unread];
    }

    public static function channel(string $tenantId, string $userId): string
    {
        return 'school.'.$tenantId.'.user.'.$userId;
    }
}
