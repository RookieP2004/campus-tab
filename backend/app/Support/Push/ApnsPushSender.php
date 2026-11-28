<?php

namespace App\Support\Push;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * iPhone push through Apple Push Notification service (token-based: a .p8
 * key from the Apple Developer account, its key id and the team id). Uses
 * HTTP/2 as APNs requires. The signed provider token is reused for 50
 * minutes (Apple refuses tokens older than an hour and too-frequent new
 * ones). Only the alert's title and body travel.
 */
class ApnsPushSender implements PushSender
{
    public function __construct(
        private readonly string $keyId,
        private readonly string $teamId,
        private readonly string $bundleId,
        private readonly string $privateKey,
        private readonly bool $sandbox,
    ) {}

    public static function fromConfig(): self
    {
        $c = (array) config('communication.push.apns');
        $key = (string) ($c['key'] ?? '');
        $pem = str_contains($key, 'BEGIN PRIVATE KEY') ? $key : (string) base64_decode($key, true);
        foreach (['key_id', 'team_id', 'bundle_id'] as $field) {
            if (empty($c[$field])) {
                throw new RuntimeException('APNs needs APNS_KEY_ID, APNS_TEAM_ID, APNS_BUNDLE_ID and APNS_KEY.');
            }
        }
        if (! str_contains($pem, 'BEGIN PRIVATE KEY')) {
            throw new RuntimeException('APNS_KEY must be the .p8 key (or the .p8 file in base64).');
        }

        return new self((string) $c['key_id'], (string) $c['team_id'], (string) $c['bundle_id'], $pem, (bool) ($c['sandbox'] ?? false));
    }

    public function send(array $devices, string $title, string $body): array
    {
        $host = $this->sandbox ? 'https://api.sandbox.push.apple.com' : 'https://api.push.apple.com';
        $gone = [];

        foreach ($devices as $device) {
            if (! ctype_xdigit($device['token'])) {
                $gone[] = $device['token'];

                continue;
            }
            $response = Http::withOptions(['version' => 2.0])->timeout(10)
                ->withHeaders([
                    'authorization' => 'bearer '.$this->providerToken(),
                    'apns-topic' => $this->bundleId,
                    'apns-push-type' => 'alert',
                    'apns-priority' => '10',
                ])
                ->post($host.'/3/device/'.$device['token'], ['aps' => ['alert' => ['title' => $title, 'body' => $body], 'sound' => 'default']]);
            if ($response->successful()) {
                continue;
            }
            $reason = (string) $response->json('reason', '');
            if ($response->status() === 410 || in_array($reason, ['BadDeviceToken', 'Unregistered', 'DeviceTokenNotForTopic'], true)) {
                $gone[] = $device['token'];

                continue;
            }
            Log::warning('APNs push failed', ['status' => $response->status(), 'reason' => $reason]);
        }

        return $gone;
    }

    private function providerToken(): string
    {
        return Cache::remember('push:apns:token:'.$this->keyId, now()->addMinutes(50), fn (): string => Jwt::sign(
            ['alg' => 'ES256', 'kid' => $this->keyId],
            ['iss' => $this->teamId, 'iat' => time()],
            $this->privateKey,
        ));
    }
}
