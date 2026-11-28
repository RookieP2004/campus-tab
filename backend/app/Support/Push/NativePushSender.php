<?php

namespace App\Support\Push;

/**
 * PUSH_DRIVER=native: Android devices go to FCM, iPhones to APNs. A side
 * with no keys configured is skipped (null), so Android push can run before
 * the Apple Developer account exists.
 */
class NativePushSender implements PushSender
{
    public function __construct(private readonly ?PushSender $android, private readonly ?PushSender $ios) {}

    public function send(array $devices, string $title, string $body): array
    {
        $gone = [];
        foreach (['android' => $this->android, 'ios' => $this->ios] as $platform => $sender) {
            $mine = array_values(array_filter($devices, fn (array $d) => $d['platform'] === $platform));
            if ($mine !== [] && $sender !== null) {
                $gone = [...$gone, ...$sender->send($mine, $title, $body)];
            }
        }

        return $gone;
    }
}
