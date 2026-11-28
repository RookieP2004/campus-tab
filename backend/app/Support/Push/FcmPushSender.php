<?php

namespace App\Support\Push;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Android push through Firebase Cloud Messaging (HTTP v1 API).
 *
 * Signs in to Google with the Firebase service account (a JWT signed with
 * its private key, exchanged for an access token that is cached for 50
 * minutes), then sends one message per device. Only the title and body
 * travel: no data payload, nothing about the person.
 */
class FcmPushSender implements PushSender
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    /**
     * @param  array{project_id: string, client_email: string, private_key: string, token_uri?: string}  $account  the service account JSON
     */
    public function __construct(private readonly array $account) {}

    public static function fromConfig(): self
    {
        $raw = (string) config('communication.push.fcm.credentials');
        $json = str_starts_with(trim($raw), '{') ? $raw : (string) base64_decode($raw, true);
        $account = json_decode($json, true);
        if (! is_array($account) || empty($account['project_id']) || empty($account['client_email']) || empty($account['private_key'])) {
            throw new RuntimeException('FCM_CREDENTIALS must be the Firebase service account JSON (or that JSON in base64).');
        }

        /** @var array{project_id: string, client_email: string, private_key: string, token_uri?: string} $account */
        return new self($account);
    }

    public function send(array $devices, string $title, string $body): array
    {
        if ($devices === []) {
            return [];
        }
        $url = 'https://fcm.googleapis.com/v1/projects/'.rawurlencode($this->account['project_id']).'/messages:send';
        $token = $this->accessToken();
        $gone = [];

        foreach ($devices as $device) {
            $response = Http::withToken($token)->timeout(10)->acceptJson()->post($url, [
                'message' => [
                    'token' => $device['token'],
                    'notification' => ['title' => $title, 'body' => $body],
                    'android' => ['priority' => 'HIGH'],
                ],
            ]);
            if ($response->successful()) {
                continue;
            }
            $code = (string) $response->json('error.details.0.errorCode', $response->json('error.status', ''));
            if ($response->status() === 404 || $code === 'UNREGISTERED') {
                $gone[] = $device['token'];

                continue;
            }
            // Never log the token; the status is enough to investigate.
            Log::warning('FCM push failed', ['status' => $response->status(), 'code' => $code]);
        }

        return $gone;
    }

    private function accessToken(): string
    {
        return Cache::remember('push:fcm:token:'.hash('sha256', $this->account['client_email']), now()->addMinutes(50), function (): string {
            $now = time();
            $jwt = Jwt::sign(['alg' => 'RS256', 'typ' => 'JWT'], [
                'iss' => $this->account['client_email'],
                'scope' => self::SCOPE,
                'aud' => $this->account['token_uri'] ?? 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ], $this->account['private_key']);

            $response = Http::asForm()->timeout(10)->post($this->account['token_uri'] ?? 'https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);
            $token = $response->json('access_token');
            if (! $response->successful() || ! is_string($token)) {
                throw new RuntimeException('FCM sign-in failed (HTTP '.$response->status().').');
            }

            return $token;
        });
    }
}
