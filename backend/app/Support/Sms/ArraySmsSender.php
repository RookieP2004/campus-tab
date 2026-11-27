<?php

namespace App\Support\Sms;

/** Test driver: keeps every message so a test can read the OTP. */
class ArraySmsSender implements SmsSender
{
    /** @var list<array{to: string, message: string}> */
    public array $messages = [];

    public function send(string $to, string $message): void
    {
        $this->messages[] = ['to' => $to, 'message' => $message];
    }

    public function lastTo(string $to): ?string
    {
        foreach (array_reverse($this->messages) as $sms) {
            if ($sms['to'] === $to) {
                return $sms['message'];
            }
        }

        return null;
    }
}
