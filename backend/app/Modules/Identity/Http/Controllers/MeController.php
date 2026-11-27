<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Audit\Domain\AuditRecorder;
use App\Modules\Identity\Domain\PasswordLinks;
use App\Modules\Identity\Domain\PasswordPolicy;
use App\Modules\Identity\Domain\SessionIssuer;
use App\Modules\Identity\Domain\SupportAgentUser;
use App\Modules\Identity\Http\Middleware\TrackDeviceSession;
use App\Modules\Identity\Models\DeviceSession;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * The signed-in user's own account. Self-service only: nothing here acts on
 * anyone else, so these routes need no permission beyond being signed in.
 *
 *   GET    /me                       profile, roles, permissions, preferences
 *   PATCH  /me/preferences           { language?, theme? }
 *   PUT    /me/password              { current_password, password, password_confirmation }
 *   GET    /me/sessions              signed-in devices
 *   DELETE /me/sessions/{id}         sign out one device
 *   POST   /me/sessions/revoke-others  sign out every other device
 */
class MeController
{
    public function show(Request $request): JsonResponse
    {
        $user = $this->user($request);
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');
        $session = $request->attributes->get('device_session');
        $session = $session instanceof DeviceSession ? $session : null;

        return response()->json(['data' => [
            'id' => (string) $user->getKey(),
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'roles' => $user->roles,
            'level' => $user->level(),
            // For hiding what the user cannot do. The server checks every request anyway.
            'permissions' => $user->permissions(),
            'language' => $user->language,
            'theme' => $user->theme,
            'mfa' => ['enabled' => $user->mfaEnabled(), 'required' => $user->mfaRequired()],
            'school' => ['code' => $tenant->code, 'name' => $tenant->name],
            // For the screens: warn a minute before an idle sign-out.
            'session' => [
                'idle_minutes' => TrackDeviceSession::idleMinutes($user),
                'ends_at' => $session?->created_at?->copy()->addHours((int) config('identity.sessions.max_hours', 8))->toIso8601String(),
            ],
        ]]);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        // Every user may choose any language the app ships; no school setting turns this off.
        $input = $request->validate([
            'language' => ['sometimes', 'string', Rule::in(config('identity.languages'))],
            'theme' => ['sometimes', 'string', Rule::in(config('identity.themes'))],
        ]);

        $user = $this->user($request);
        $user->forceFill($input)->save();

        return response()->json(['data' => ['language' => $user->language, 'theme' => $user->theme]]);
    }

    /**
     * Needs the current password. Ends every session and token of the user,
     * this one included, keeps MFA on, and tells the user.
     */
    public function changePassword(Request $request, PasswordLinks $links): JsonResponse
    {
        $input = $request->validate([
            'current_password' => ['required', 'string', 'max:200'],
            'password' => PasswordPolicy::rules(),
        ]);

        $user = $this->user($request);

        if (! is_string($user->password) || ! Hash::check($input['current_password'], $user->password)) {
            throw new ApiException('current_password_wrong', 'Your current password is not right.', 422);
        }

        PasswordPolicy::assertNotPersonal($input['password'], $user->personalWords());

        $user->forceFill(['password' => $input['password']])->save();

        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');
        $ended = $links->afterChange($tenant, $user, 'password.changed');

        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
        }

        return response()->json(['data' => ['sessions_ended' => $ended, 'sign_in_again' => true]]);
    }

    public function sessions(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $current = $this->currentSession($request);

        $sessions = DeviceSession::query()
            ->where('user_id', (string) $user->getKey())
            ->whereNull('revoked_at')
            ->orderBy('last_seen_at', 'desc')
            ->get();

        return response()->json(['data' => $sessions->map(fn (DeviceSession $s) => [
            'id' => (string) $s->getKey(),
            'kind' => $s->kind,
            'name' => $s->name,
            'user_agent' => $s->user_agent,
            'ip' => $s->ip,
            'method' => $s->method,
            'signed_in_at' => $s->created_at?->toIso8601String(),
            'last_seen_at' => $s->last_seen_at?->toIso8601String(),
            'current' => (string) $s->getKey() === (string) $current->getKey(),
        ])->values()]);
    }

    public function revokeSession(Request $request, string $id, SessionIssuer $issuer, AuditRecorder $audit): JsonResponse
    {
        $user = $this->user($request);

        /** @var DeviceSession|null $session */
        $session = DeviceSession::query()->where('user_id', (string) $user->getKey())->find($id);

        if ($session === null) {
            throw new ApiException('not_found', 'The record was not found.', 404);
        }

        $issuer->revoke($session, 'signed_out_remotely');
        $audit->record('sign_out.remote', 'security', 'device_sessions', (string) $session->getKey());

        return response()->json(['data' => ['signed_out' => true]]);
    }

    public function revokeOthers(Request $request, SessionIssuer $issuer, AuditRecorder $audit): JsonResponse
    {
        $user = $this->user($request);
        $ended = $issuer->revokeAllFor($user, 'signed_out_remotely', (string) $this->currentSession($request)->getKey());
        $audit->record('sign_out.others', 'security', 'users', (string) $user->getKey(), meta: ['sessions_ended' => $ended]);

        return response()->json(['data' => ['sessions_ended' => $ended]]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User || $user instanceof SupportAgentUser) {
            throw new ApiException('school_account_required', 'Sign in with a school account to continue.', 403);
        }

        return $user;
    }

    private function currentSession(Request $request): DeviceSession
    {
        $session = $request->attributes->get('device_session');

        return $session instanceof DeviceSession ? $session : throw new ApiException('session_required', 'Sign in on this device first.', 401);
    }
}
