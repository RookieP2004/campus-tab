<?php

namespace App\Support\Push;

/** Test driver: keeps every push so a test can read it. */
class ArrayPushSender implements PushSender
{
    /** @var list<array{devices: list<array{token: string, platform: string}>, title: string, body: string}> */
    public array $sent = [];

    /** @var list<string> tokens to report as gone on the next send (tests) */
    public array $gone = [];

    public function send(array $devices, string $title, string $body): array
    {
        $this->sent[] = ['devices' => $devices, 'title' => $title, 'body' => $body];

        return array_values(array_intersect(array_column($devices, 'token'), $this->gone));
    }
}
