<?php

namespace App\Support\Push;

use Illuminate\Support\Facades\Log;

/** Development driver: writes the push to the log (never the device tokens). */
class LogPushSender implements PushSender
{
    public function send(array $tokens, string $title, string $body): void
    {
        Log::info('Push (log driver)', ['devices' => count($tokens), 'title' => $title, 'body' => $body]);
    }
}
