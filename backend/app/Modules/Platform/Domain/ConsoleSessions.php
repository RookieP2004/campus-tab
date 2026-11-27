<?php

namespace App\Modules\Platform\Domain;

use App\Modules\Audit\Domain\PlatformAuditRecorder;
use App\Modules\Identity\Models\PlatformAdmin;
use App\Modules\Platform\Models\PlatformSession;
use App\Support\Http\ApiException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Starts and ends console sessions. The console runs in the web app only, so
 * it always uses the session cookie (guard "console"), never a token. One
 * identity per browser: signing in here signs the browser out of any school
 * account first.
 */
class ConsoleSessions
{
    public function __construct(private readonly PlatformAuditRecorder $audit) {}

    public static function requireBrowser(Request $request): void
    {
        if (! $request->hasSession()) {
            throw new ApiException('console_browser_only', 'The platform console works only in the Campus TAB web app.', 403);
        }
    }

    public function start(Request $request, PlatformAdmin $admin, string $method, bool $limited): PlatformSession
    {
        self::requireBrowser($request);

        Auth::guard('web')->logout();
        Auth::guard('console')->login($admin);
        $request->session()->regenerate();

        $session = PlatformSession::create([
            'admin_id' => (string) $admin->getKey(),
            'session_hash' => PlatformSession::hashSessionId($request->session()->getId()),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 300),
            'ip' => $request->ip(),
            'method' => $method,
            'limited' => $limited,
            'last_seen_at' => now(),
        ]);

        $request->attributes->set('platform_session', $session);

        return $session;
    }

    public function current(Request $request): ?PlatformSession
    {
        if (! $request->hasSession()) {
            return null;
        }

        return PlatformSession::query()
            ->where('session_hash', PlatformSession::hashSessionId($request->session()->getId()))
            ->first();
    }

    public function revoke(PlatformSession $session, string $reason): void
    {
        if ($session->isRevoked()) {
            return;
        }

        $session->forceFill(['revoked_at' => now(), 'revoked_reason' => $reason])->save();
    }

    public function revokeAllFor(PlatformAdmin $admin, string $reason, ?string $exceptId = null): int
    {
        $count = 0;

        foreach (PlatformSession::query()->where('admin_id', (string) $admin->getKey())->whereNull('revoked_at')->get() as $session) {
            if ($exceptId !== null && (string) $session->getKey() === $exceptId) {
                continue;
            }

            $this->revoke($session, $reason);
            $count++;
        }

        return $count;
    }

    public function end(Request $request, PlatformAdmin $admin, string $reason = 'signed_out'): void
    {
        $session = $request->attributes->get('platform_session');

        if ($session instanceof PlatformSession) {
            $this->revoke($session, $reason);
            $this->audit->record('console.sign_out', $admin, entity: 'platform_sessions', entityId: (string) $session->getKey());
        }

        self::forget($request);
    }

    public static function forget(Request $request): void
    {
        Auth::guard('console')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }
}
