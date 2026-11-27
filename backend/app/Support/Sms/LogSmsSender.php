<?php

namespace App\Support\Sms;

use Illuminate\Support\Facades\Log;

/** Development driver: the message, OTPs included, goes to storage/logs. */
class LogSmsSender implements SmsSender
{
    public function send(string $to, string $message): void
    {
        Log::info('SMS (log driver)', ['to' => $to, 'message' => $message]);
    }
}
