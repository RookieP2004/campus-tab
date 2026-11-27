<?php

namespace App\Support\Push;

/**
 * Sends a push notification to devices (FCM in the apps, Phase 9). The text
 * must carry no private details: lock screens are public (Notifier makes
 * sure of it).
 */
interface PushSender
{
    /**
     * @param  list<string>  $tokens
     */
    public function send(array $tokens, string $title, string $body): void;
}
