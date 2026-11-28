<?php

namespace App\Support\Push;

/**
 * Sends a push notification to devices: FCM for Android, APNs for iOS
 * (NativePushSender). The text must carry no private details: lock screens
 * are public (Notifier makes sure of it).
 */
interface PushSender
{
    /**
     * @param  list<array{token: string, platform: string}>  $devices
     * @return list<string> tokens the push service says are gone (uninstalled app, expired token): the caller forgets them
     */
    public function send(array $devices, string $title, string $body): array;
}
