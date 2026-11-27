<?php

namespace App\Modules\Communication\Domain;

use App\Modules\Communication\Events\NotificationCreated;
use App\Modules\Communication\Jobs\SendOutsideNotifications;
use App\Modules\Communication\Models\Notification;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Tenant;

/**
 * The notification service: in the app (stored, then a nudge over Reverb on
 * the user's private channel), by push and by email, following each
 * person's preferences.
 *
 * In the app the notification may name things (it is read only after
 * sign-in). Push and email carry only the generic text for the type, such
 * as "New notice from Sunrise Public School. Open Campus TAB to read it.",
 * because phone lock screens and inboxes are not private. The generic texts
 * live in config/communication.php.
 */
class Notifier
{
    /**
     * @param  iterable<User>  $users
     * @param  array<string, mixed>  $data
     * @return int how many were notified
     */
    public function notify(Tenant $tenant, iterable $users, string $type, string $title, ?string $body = null, ?string $link = null, array $data = []): int
    {
        $ids = [];

        foreach ($users as $user) {
            if (! $user->isActive()) {
                continue;
            }

            $notification = Notification::create([
                'user_id' => (string) $user->getKey(),
                'type' => $type,
                'title' => $title,
                'body' => $body,
                'link' => $link,
                'data' => $data,
            ]);

            $unread = Notification::query()->where('user_id', (string) $user->getKey())->whereNull('read_at')->count();
            event(new NotificationCreated($tenant->id, (string) $user->getKey(), (string) $notification->getKey(), $type, $unread));

            $ids[] = (string) $user->getKey();
        }

        if ($ids !== []) {
            SendOutsideNotifications::dispatch($tenant->id, $ids, $type);
        }

        return count($ids);
    }

    /** The text push and email use for a type: never any private detail. */
    public static function genericText(string $type, string $schoolName): string
    {
        $texts = (array) config('communication.generic_texts', []);
        $text = (string) ($texts[$type] ?? $texts['default'] ?? 'There is something new for you in Campus TAB.');

        return str_replace(':school', $schoolName, $text);
    }

    /**
     * A person's notification preferences, with the defaults filled in.
     *
     * @return array{push: bool, email: bool, muted: list<string>}
     */
    public static function preferences(User $user): array
    {
        /** @var array{push?: bool, email?: bool, muted?: list<string>} $stored */
        $stored = (array) ($user->getAttribute('notification_prefs') ?? []);
        $defaults = (array) config('communication.default_preferences', ['push' => true, 'email' => false]);

        return [
            'push' => (bool) ($stored['push'] ?? $defaults['push'] ?? true),
            'email' => (bool) ($stored['email'] ?? $defaults['email'] ?? false),
            'muted' => array_values($stored['muted'] ?? []),
        ];
    }
}
