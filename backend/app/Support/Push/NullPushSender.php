<?php

namespace App\Support\Push;

/**
 * Push switched off (PUSH_DRIVER=none): the default until the apps and an
 * FCM project exist (Phase 9). In-app and email notifications still work.
 */
class NullPushSender implements PushSender
{
    public function send(array $tokens, string $title, string $body): void {}
}
