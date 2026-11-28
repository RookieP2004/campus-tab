<?php

namespace App\Support\Push;

/**
 * Push switched off (PUSH_DRIVER=none): the default until a Firebase project
 * and an APNs key exist. In-app and email notifications still work.
 */
class NullPushSender implements PushSender
{
    public function send(array $devices, string $title, string $body): array
    {
        return [];
    }
}
