<?php

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Domain\SessionIssuer;
use App\Modules\Identity\Domain\SupportAgentUser;
use App\Modules\Identity\Models\DeviceSession;
use App\Modules\Identity\Models\PersonalAccessToken;
use App\Modules\Identity\Models\User;
use App\Support\Http\ApiException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `device`. Runs after `tenant` and finds this request's device
 * session: by token record id for app tokens, by the hash of the session id
 * for the browser cookie.
 *
 *  - none, or revoked (remote sign-out, password change, deactivation,
 *    MFA reset): 401 session_revoked
 *  - unused for 15 minutes (level 60 and up) or 30 minutes (everyone else),
 *    or signed in for more than 8 hours: 401 session_expired. The same
 *    rule for browser cookies and app tokens; values in config/identity.php.
 *
 * Support agents have no device session; their grant is checked instead.
 */
class TrackDeviceSession
{
    public function __construct(private readonly SessionIssuer $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof SupportAgentUser || ! $user instanceof User) {
            return $next($request);
        }

        $token = $user->currentAccessToken();
        $isToken = $token instanceof PersonalAccessToken;

        $session = $isToken
            ? DeviceSession::query()->where('token_id', (string) $token->getKey())->first()
            : ($request->hasSession()
                ? DeviceSession::query()->where('session_hash', DeviceSession::hashSessionId($request->session()->getId()))->first()
                : null);

        if (! $session instanceof DeviceSession || $session->isRevoked() || $session->user_id !== (string) $user->getKey()) {
            $this->signOut($request, $token);

            throw new ApiException('session_revoked', 'You have been signed out on this device. Sign in again.', 401);
        }

        $idle = self::idleMinutes($user);
        $maxHours = (int) config('identity.sessions.max_hours', 8);
        $lastSeen = $session->last_seen_at ?? $session->created_at;
        $idleOut = $lastSeen !== null && $lastSeen->lessThan(now()->subMinutes($idle));
        $tooLong = $session->created_at !== null && $session->created_at->lessThan(now()->subHours($maxHours));

        if ($idleOut || $tooLong) {
            $this->sessions->revoke($session, $tooLong ? 'max_age' : 'idle');
            $this->signOut($request, $token);

            throw new ApiException('session_expired', $tooLong
                ? 'You have been signed in for '.$maxHours.' hours. Sign in again.'
                : 'You were signed out after '.$idle.' minutes without use. Sign in again.', 401);
        }

        // Every request counts as use; written at most every 30 seconds.
        if ($lastSeen === null || $lastSeen->lessThan(now()->subSeconds(30))) {
            $session->forceFill(['last_seen_at' => now(), 'ip' => $request->ip()])->save();
        }

        $request->attributes->set('device_session', $session);

        return $next($request);
    }

    /** 15 minutes for level 60 and up, 30 for everyone else (config/identity.php). */
    public static function idleMinutes(User $user): int
    {
        return $user->level() >= (int) config('identity.mfa.required_from_level', 60)
            ? (int) config('identity.sessions.idle_minutes_mfa_roles', 15)
            : (int) config('identity.sessions.idle_minutes', 30);
    }

    private function signOut(Request $request, mixed $token): void
    {
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
        }
    }
}
