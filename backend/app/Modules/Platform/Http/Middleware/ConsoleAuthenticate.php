<?php

namespace App\Modules\Platform\Http\Middleware;

use App\Modules\Identity\Http\Middleware\TrackDeviceSession;
use App\Modules\Identity\Models\PlatformAdmin;
use App\Modules\Platform\Domain\ConsoleSessions;
use App\Modules\Platform\Models\PlatformSession;
use App\Support\Http\ApiException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `console`. Every console route except sign-in runs behind it.
 *
 *  - Signed in with the console guard (session cookie only), or 401
 *    unauthenticated.
 *  - The console session must exist and not be revoked (401
 *    session_revoked), and must have been used in the last 15 minutes and
 *    started within 8 hours (401 session_expired). A request marked
 *    X-Background-Request: 1 is checked but never counts as use.
 *  - A session that may only enrol MFA reaches only the routes marked with
 *    the `console_enrol` default; anything else is 403 mfa_enrolment_required.
 */
class ConsoleAuthenticate
{
    public function __construct(private readonly ConsoleSessions $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession()) {
            throw new ApiException('console_browser_only', 'The platform console works only in the Campus TAB web app.', 403);
        }

        $admin = Auth::guard('console')->user();

        if (! $admin instanceof PlatformAdmin) {
            throw new ApiException('unauthenticated', 'Sign in to continue.', 401);
        }

        $session = $this->sessions->current($request);

        if (! $session instanceof PlatformSession || $session->isRevoked() || $session->admin_id !== (string) $admin->getKey() || ! $admin->isActive()) {
            ConsoleSessions::forget($request);

            throw new ApiException('session_revoked', 'You have been signed out on this device. Sign in again.', 401);
        }

        $idle = (int) config('identity.platform.idle_minutes', 15);
        $maxHours = (int) config('identity.platform.max_hours', 8);
        $lastSeen = $session->last_seen_at ?? $session->created_at;
        $idleOut = $lastSeen !== null && $lastSeen->lessThan(now()->subMinutes($idle));
        $tooLong = $session->created_at !== null && $session->created_at->lessThan(now()->subHours($maxHours));

        if ($idleOut || $tooLong) {
            $this->sessions->revoke($session, $tooLong ? 'max_age' : 'idle');
            ConsoleSessions::forget($request);

            throw new ApiException('session_expired', $tooLong
                ? 'You have been signed in for '.$maxHours.' hours. Sign in again.'
                : 'You were signed out after '.$idle.' minutes without use. Sign in again.', 401);
        }

        if (! TrackDeviceSession::isBackground($request) && ($lastSeen === null || $lastSeen->lessThan(now()->subSeconds(30)))) {
            $session->forceFill(['last_seen_at' => now(), 'ip' => $request->ip()])->save();
        }

        $enrolRoute = (bool) ($request->route()?->defaults['console_enrol'] ?? false);

        if (($session->limited || ! $admin->mfaEnabled()) && ! $enrolRoute) {
            throw new ApiException('mfa_enrolment_required', 'Set up two-step sign-in before you continue.', 403);
        }

        $request->attributes->set('platform_session', $session);
        $request->setUserResolver(fn () => $admin);

        return $next($request);
    }
}
