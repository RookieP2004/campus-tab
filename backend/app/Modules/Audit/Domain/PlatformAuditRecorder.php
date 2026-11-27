<?php

namespace App\Modules\Audit\Domain;

use App\Modules\Audit\Models\PlatformAuditLog;
use App\Modules\Identity\Models\PlatformAdmin;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;

/**
 * Writes the platform audit log: every console action and platform:*
 * command. When the action is on a school (licence issued, revoked or
 * changed, school created, sole-Chief recovery), pass the school and the
 * same entry is also written to that school's own audit log, with the
 * platform admin as the actor, so the school sees what we did.
 *
 * Secrets are stripped by Redactor, as in the school log.
 */
class PlatformAuditRecorder
{
    public function __construct(
        private readonly AuditRecorder $schoolAudit,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  PlatformAdmin|null  $actor  null for a command run on the server
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  array<string, mixed>  $meta
     * @param  string|null  $schoolCategory  when set, the entry also goes to the school's log in this category
     */
    public function record(
        string $action,
        ?PlatformAdmin $actor,
        ?Tenant $school = null,
        ?string $entity = null,
        ?string $entityId = null,
        ?array $before = null,
        ?array $after = null,
        array $meta = [],
        ?string $schoolCategory = null,
    ): PlatformAuditLog {
        $redacted = [];
        $cleanBefore = Redactor::clean($before, $redacted, 'before');
        $cleanAfter = Redactor::clean($after, $redacted, 'after');
        $cleanMeta = Redactor::clean($meta, $redacted, 'meta') ?? [];
        $request = $this->request();
        $session = $request?->attributes->get('platform_session');

        $actorInfo = $actor instanceof PlatformAdmin
            ? ['type' => 'platform_admin', 'id' => (string) $actor->getKey(), 'name' => $actor->name]
            : ['type' => 'system', 'id' => null, 'name' => app()->runningInConsole() ? 'command line' : 'system'];

        $entry = PlatformAuditLog::create([
            'actor' => $actorInfo,
            'actor_id' => $actorInfo['id'],
            'action' => $action,
            'school_id' => $school?->id,
            'school_code' => $school?->code,
            'entity' => $entity,
            'entity_id' => $entityId,
            'before' => $cleanBefore,
            'after' => $cleanAfter,
            'redacted' => array_values(array_unique($redacted)),
            'meta' => $cleanMeta,
            'ip' => $request?->ip(),
            'device' => $request === null ? null : [
                'session_id' => is_object($session) && method_exists($session, 'getKey') ? (string) $session->getKey() : null,
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 300),
            ],
        ]);

        if ($school instanceof Tenant && $schoolCategory !== null) {
            $this->context->run($school->id, fn () => $this->schoolAudit->record(
                'platform.'.$action,
                $schoolCategory,
                $entity,
                $entityId,
                $before,
                $after,
                array_merge($meta, ['platform_audit_id' => (string) $entry->getKey()]),
                $actor,
            ));
        }

        return $entry;
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
