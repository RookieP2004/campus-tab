<?php

namespace App\Modules\Identity\Domain;

use App\Modules\Audit\Domain\AuditRecorder;
use App\Modules\Identity\Models\DeviceSession;
use App\Modules\Identity\Models\PersonalAccessToken;
use App\Modules\Identity\Models\User;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

/**
 * Starts and ends signed-in devices.
 *
 * A request from the web app's own address (it carries a session) gets the
 * Sanctum session cookie; anything else gets a bearer token. Either way a
 * DeviceSession records it, holding only the token's record id or a hash of
 * the session id. A "limited" session may only enrol MFA.
 */
class SessionIssuer
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function isBrowser(Request $request): bool
    {
        return $request->hasSession() && EnsureFrontendRequestsAreStateful::fromFrontend($request);
    }

    /**
     * @return array<string, mixed> what the sign-in response returns
     */
    public function issue(Request $request, User $user, string $method, bool $limited = false): array
    {
        if ($this->isBrowser($request)) {
            // One identity per browser: a platform console session here ends first.
            Auth::guard('console')->logout();
            Auth::guard('web')->login($user);
            $request->session()->regenerate();

            $session = $this->record($user, DeviceSession::KIND_BROWSER, [
                'session_hash' => DeviceSession::hashSessionId($request->session()->getId()),
            ], $this->deviceName($request), $request, $method, $limited);

            $request->session()->put('device_session_id', (string) $session->getKey());

            return [
                'mode' => 'browser',
                'device_session_id' => (string) $session->getKey(),
                'mfa_enrolment_required' => $limited,
            ];
        }

        return $this->issueToken($user, $this->deviceName($request), $method, $limited, $request);
    }

    /**
     * @return array{mode: string, token: string, token_type: string, token_abilities: list<string>, token_expires_at: string|null, device_session_id: string, mfa_enrolment_required: bool}
     */
    public function issueToken(User $user, string $name, string $method, bool $limited = false, ?Request $request = null): array
    {
        $abilities = $limited ? ['mfa:enrol'] : ['*'];
        $expires = $limited
            ? now()->addMinutes((int) config('identity.mfa.enrolment_token_minutes', 30))
            : now()->addHours((int) config('identity.sessions.max_hours', 8));
        $token = $user->createToken($name, $abilities, $expires);

        $session = $this->record($user, DeviceSession::KIND_TOKEN, [
            'token_id' => (string) $token->accessToken->getKey(),
        ], $name, $request, $method, $limited);

        $expiresAt = $token->accessToken->getAttribute('expires_at');

        return [
            'mode' => 'token',
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'token_abilities' => $abilities,
            'token_expires_at' => $expiresAt instanceof DateTimeInterface ? $expiresAt->format(DATE_ATOM) : null,
            'device_session_id' => (string) $session->getKey(),
            'mfa_enrolment_required' => $limited,
        ];
    }

    /** Ends one device: deletes its token, or marks the browser session revoked. */
    public function revoke(DeviceSession $session, string $reason): void
    {
        if ($session->isRevoked()) {
            return;
        }

        $session->forceFill(['revoked_at' => now(), 'revoked_reason' => $reason])->save();

        if ($session->kind === DeviceSession::KIND_TOKEN && is_string($session->token_id)) {
            PersonalAccessToken::query()->whereKey($session->token_id)->delete();
        }
    }

    /** Ends every device of a user, except one (the current one) when given. */
    public function revokeAllFor(User $user, string $reason, ?string $exceptId = null): int
    {
        $count = 0;

        $sessions = DeviceSession::query()
            ->where('user_id', (string) $user->getKey())
            ->whereNull('revoked_at')
            ->get();

        foreach ($sessions as $session) {
            if ($exceptId !== null && (string) $session->getKey() === $exceptId) {
                continue;
            }

            $this->revoke($session, $reason);
            $count++;
        }

        // Tokens made outside a device session (none should exist) go too.
        if ($exceptId === null) {
            PersonalAccessToken::query()
                ->where('tokenable_type', 'user')
                ->where('tokenable_id', (string) $user->getKey())
                ->delete();
        }

        return $count;
    }

    /** Signs out the current device. */
    public function endCurrent(Request $request, string $reason = 'signed_out'): void
    {
        $session = $request->attributes->get('device_session');

        if ($session instanceof DeviceSession) {
            $this->revoke($session, $reason);
            $this->audit->record('sign_out', 'security', 'device_sessions', (string) $session->getKey());
        }

        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }

    /**
     * @param  array<string, string>  $handle
     */
    private function record(User $user, string $kind, array $handle, string $name, ?Request $request, string $method, bool $limited): DeviceSession
    {
        return DeviceSession::create(array_merge($handle, [
            'user_id' => (string) $user->getKey(),
            'kind' => $kind,
            'name' => $name,
            'user_agent' => $request === null ? null : mb_substr((string) $request->userAgent(), 0, 300),
            'ip' => $request?->ip(),
            'method' => $method,
            'limited' => $limited,
            'last_seen_at' => now(),
        ]));
    }

    private function deviceName(Request $request): string
    {
        $name = trim((string) $request->input('device_name', ''));

        if ($name === '') {
            $name = mb_substr((string) $request->userAgent(), 0, 60) ?: 'Unknown device';
        }

        return mb_substr($name, 0, 100);
    }
}
