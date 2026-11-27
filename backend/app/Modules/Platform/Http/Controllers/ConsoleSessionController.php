<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Audit\Domain\PlatformAuditRecorder;
use App\Modules\Platform\Domain\ConsoleSessions;
use App\Modules\Platform\Models\PlatformSession;
use App\Support\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The platform admin's own signed-in browsers.
 *
 *   GET    /platform/me/sessions
 *   DELETE /platform/me/sessions/{id}   sign out that browser
 */
class ConsoleSessionController
{
    public function index(Request $request): JsonResponse
    {
        $admin = ConsoleAuthController::admin($request);
        $current = $request->attributes->get('platform_session');

        $sessions = PlatformSession::query()
            ->where('admin_id', (string) $admin->getKey())
            ->whereNull('revoked_at')
            ->orderBy('last_seen_at', 'desc')
            ->get();

        return response()->json(['data' => $sessions->map(fn (PlatformSession $s) => [
            'id' => (string) $s->getKey(),
            'kind' => 'browser',
            'name' => 'Console',
            'user_agent' => $s->user_agent,
            'ip' => $s->ip,
            'method' => $s->method,
            'signed_in_at' => $s->created_at?->toIso8601String(),
            'last_seen_at' => $s->last_seen_at?->toIso8601String(),
            'current' => $current instanceof PlatformSession && (string) $s->getKey() === (string) $current->getKey(),
        ])->values()]);
    }

    public function destroy(Request $request, string $id, ConsoleSessions $sessions, PlatformAuditRecorder $audit): JsonResponse
    {
        $admin = ConsoleAuthController::admin($request);

        /** @var PlatformSession|null $session */
        $session = PlatformSession::query()->where('admin_id', (string) $admin->getKey())->find($id);

        if ($session === null) {
            throw new ApiException('not_found', 'The record was not found.', 404);
        }

        $sessions->revoke($session, 'signed_out_remotely');
        $audit->record('console.sign_out.remote', $admin, entity: 'platform_sessions', entityId: (string) $session->getKey());

        return response()->json(['data' => ['signed_out' => true]]);
    }
}
