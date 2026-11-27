<?php

namespace App\Modules\Audit\Domain;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Domain\SupportAgentUser;
use App\Modules\Identity\Models\DeviceSession;
use App\Modules\Identity\Models\PlatformAdmin;
use App\Modules\Identity\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Writes audit entries for the current school.
 *
 *   - Model changes come from the Auditable trait (created, updated,
 *     deleted, restored, purged).
 *   - Named events (sign-in, lockout, MFA, step-up, support sessions, chief
 *     added or removed) are recorded with record().
 *
 * Who did it is taken from the signed-in user unless an actor is given. A
 * support agent is recorded as the agent, with the grant id beside it.
 * Secrets are removed by Redactor before anything is written.
 */
class AuditRecorder
{
    public const CATEGORIES = ['academic', 'admin', 'security', 'fees'];

    private static int $muted = 0;

    /** @var array<string, mixed> meta added to every entry, e.g. an import batch id */
    private static array $contextMeta = [];

    /**
     * Run a closure with extra meta on every entry it writes, for example
     * ['import_batch_id' => …] so a whole import is audited under one id.
     *
     * @template T
     *
     * @param  array<string, mixed>  $meta
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function withMeta(array $meta, Closure $callback): mixed
    {
        $previous = self::$contextMeta;
        self::$contextMeta = array_merge($previous, $meta);

        try {
            return $callback();
        } finally {
            self::$contextMeta = $previous;
        }
    }

    /**
     * Run a closure without automatic model entries, when the caller records
     * one named entry for the whole operation instead.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function muted(Closure $callback): mixed
    {
        self::$muted++;

        try {
            return $callback();
        } finally {
            self::$muted--;
        }
    }

    public static function isMuted(): bool
    {
        return self::$muted > 0;
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  array<string, mixed>  $meta
     * @param  User|PlatformAdmin|null  $actor  who acted, when it is not the signed-in user
     *                                          (sign-in events; a platform admin acting from the console)
     */
    public function record(
        string $action,
        string $category,
        ?string $entity = null,
        ?string $entityId = null,
        ?array $before = null,
        ?array $after = null,
        array $meta = [],
        User|PlatformAdmin|null $actor = null,
    ): AuditLog {
        $redacted = [];
        $before = Redactor::clean($before, $redacted, 'before');
        $after = Redactor::clean($after, $redacted, 'after');
        $meta = Redactor::clean(array_merge(self::$contextMeta, $meta), $redacted, 'meta') ?? [];

        $who = $actor ?? $this->signedInUser();
        $request = $this->request();
        $deviceSession = $request?->attributes->get('device_session');

        $actorInfo = $this->describe($who);

        return AuditLog::create([
            'actor' => $actorInfo,
            // Queried on its own: the driver would rewrite a filter on "actor.id" to "actor._id".
            'actor_id' => $actorInfo['id'],
            'support_grant_id' => $who instanceof SupportAgentUser ? $who->grant->getKey() : null,
            'action' => $action,
            'category' => in_array($category, self::CATEGORIES, true) ? $category : 'admin',
            'entity' => $entity,
            'entity_id' => $entityId,
            'before' => $before,
            'after' => $after,
            'redacted' => array_values(array_unique($redacted)),
            'meta' => $meta,
            'ip' => $request?->ip(),
            'device' => $request === null ? null : [
                'session_id' => $deviceSession instanceof DeviceSession ? (string) $deviceSession->getKey() : null,
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 300),
            ],
        ]);
    }

    /**
     * Called by the Auditable trait.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function forModel(Model $model, string $action, ?array $before, ?array $after): void
    {
        if (self::isMuted()) {
            return;
        }

        $category = method_exists($model, 'auditCategory') ? (string) $model->auditCategory() : 'admin';

        $this->record($action, $category, $model->getTable(), (string) $model->getKey(), $before, $after);
    }

    /**
     * @return array{type: string, id: string|null, name: string|null}
     */
    private function describe(User|PlatformAdmin|null $user): array
    {
        if ($user instanceof PlatformAdmin) {
            return ['type' => 'platform_admin', 'id' => (string) $user->getKey(), 'name' => $user->name];
        }

        if ($user instanceof SupportAgentUser) {
            return ['type' => 'support_agent', 'id' => (string) $user->agent->getKey(), 'name' => $user->agent->name];
        }

        if ($user instanceof User) {
            return ['type' => 'user', 'id' => (string) $user->getKey(), 'name' => $user->name];
        }

        return ['type' => 'system', 'id' => null, 'name' => app()->runningInConsole() ? 'command line' : 'system'];
    }

    private function signedInUser(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    private function request(): ?Request
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = app('request');

        return $request instanceof Request && $request->server('REQUEST_URI') !== null ? $request : null;
    }
}
