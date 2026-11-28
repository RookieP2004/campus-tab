<?php

namespace App\Modules\Communication\Http\Controllers;

use App\Modules\Communication\Domain\Notifier;
use App\Modules\Communication\Models\Notification;
use App\Modules\Communication\Models\PushDevice;
use App\Modules\Identity\Models\DeviceSession;
use App\Modules\Identity\Models\User;
use App\Support\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The signed-in person's notifications and preferences.
 *
 *   GET    /me/notifications ?unread=1                 newest first, with the unread count
 *   POST   /me/notifications/{id}/read
 *   POST   /me/notifications/read-all
 *   GET    /me/notification-preferences              { push, email, muted[] }, types you can mute
 *   PUT    /me/notification-preferences
 *   POST   /me/push-devices { token, platform }      register this device for push (the apps)
 *   DELETE /me/push-devices { token }
 */
class NotificationController
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $query = Notification::query()->where('user_id', (string) $user->getKey())->orderBy('created_at', 'desc')->limit(50);

        if ($request->boolean('unread')) {
            $query->whereNull('read_at');
        }

        return response()->json([
            'data' => $query->get()->map(fn (Notification $n) => $n->present())->values(),
            'unread' => Notification::query()->where('user_id', (string) $user->getKey())->whereNull('read_at')->count(),
        ]);
    }

    public function read(Request $request, string $id): JsonResponse
    {
        $notification = Notification::query()->where('user_id', (string) $this->user($request)->getKey())->findOrFail($id);
        $notification->forceFill(['read_at' => $notification->read_at ?? now()])->save();

        return response()->json(['data' => $notification->present()]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $count = Notification::query()->where('user_id', (string) $this->user($request)->getKey())->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['data' => ['marked' => $count]]);
    }

    public function preferences(Request $request): JsonResponse
    {
        return response()->json(['data' => array_merge(Notifier::preferences($this->user($request)), ['types' => config('communication.types')])]);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $input = $request->validate([
            'push' => ['sometimes', 'boolean'],
            'email' => ['sometimes', 'boolean'],
            'muted' => ['sometimes', 'array'],
            'muted.*' => ['string', Rule::in(config('communication.types'))],
        ]);

        $user = $this->user($request);
        $user->forceFill(['notification_prefs' => array_merge(Notifier::preferences($user), $input)])->save();

        return response()->json(['data' => array_merge(Notifier::preferences($user), ['types' => config('communication.types')])]);
    }

    public function registerDevice(Request $request): JsonResponse
    {
        $input = $request->validate([
            'token' => ['required', 'string', 'min:20', 'max:4096'],
            'platform' => ['required', Rule::in(['android', 'ios', 'web', 'desktop'])],
        ]);

        $user = $this->user($request);
        $hash = PushDevice::hashToken($input['token']);
        $device = PushDevice::query()->where('token_hash', $hash)->first() ?? new PushDevice(['token_hash' => $hash]);
        // Each sign-in on the device registers again: it belongs to that
        // sign-in, and the 30-day clock starts over.
        $session = $request->attributes->get('device_session');
        $device->fill([
            'user_id' => (string) $user->getKey(), 'token' => $input['token'], 'platform' => $input['platform'],
            'device_session_id' => $session instanceof DeviceSession ? (string) $session->getKey() : null, 'last_seen_at' => now(),
        ])->save();

        return response()->json(['data' => ['registered' => true]], 201);
    }

    public function forgetDevice(Request $request): JsonResponse
    {
        $input = $request->validate(['token' => ['required', 'string', 'max:4096']]);
        PushDevice::query()->where('user_id', (string) $this->user($request)->getKey())->where('token_hash', PushDevice::hashToken($input['token']))->delete();

        return response()->json(['data' => ['removed' => true]]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new ApiException('school_account_required', 'Sign in with a school account to continue.', 403);
    }
}
