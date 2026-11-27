<?php

namespace App\Support\Sms;

/**
 * Sends a text message. The driver is chosen by SMS_DRIVER:
 *   log   - writes the message to the Laravel log (development)
 *   array - keeps messages in memory (tests)
 * A real Indian SMS gateway (DLT-registered templates) plugs in here later.
 */
interface SmsSender
{
    public function send(string $to, string $message): void;
}
