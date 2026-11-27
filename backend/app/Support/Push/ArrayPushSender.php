<?php

namespace App\Support\Push;

/** Test driver: keeps every push so a test can read it. */
class ArrayPushSender implements PushSender
{
    /** @var list<array{tokens: list<string>, title: string, body: string}> */
    public array $sent = [];

    public function send(array $tokens, string $title, string $body): void
    {
        $this->sent[] = ['tokens' => $tokens, 'title' => $title, 'body' => $body];
    }
}
