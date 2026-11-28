<?php

namespace App\Support\Push;

use Illuminate\Support\Facades\Log;

/** Development driver: writes the push to the log (never the device tokens). */
class LogPushSender implements PushSender
{
    public function send(array $devices, string $title, string $body): array
    {
        Log::info('Push (log driver)', ['devices' => count($devices), 'platforms' => array_count_values(array_column($devices, 'platform')), 'title' => $title, 'body' => $body]);

        return [];
    }
}
