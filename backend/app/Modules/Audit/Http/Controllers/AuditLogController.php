<?php

namespace App\Modules\Audit\Http\Controllers;

use App\Modules\Audit\Domain\AuditRecorder;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Domain\AccessControl;
use App\Modules\Identity\Models\User;
use App\Support\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use MongoDB\BSON\ObjectId;

/**
 * GET /audit-logs — the school's change history, newest first, 50 a page
 * (?cursor= the last id seen).
 *
 * What each reader sees:
 *   audit.view:school          everything (the Chief)
 *   audit.view_academic:school academic entries (the Principal)
 *   audit.view:own             entries of their own actions (Clerk, and the Principal too)
 *
 * Filters: entity, entity_id, action, category, actor_id, support_grant_id, from, to.
 */
class AuditLogController
{
    public function index(Request $request, AccessControl $access): JsonResponse
    {
        Gate::authorize('audit.view-any');

        /** @var User $user */
        $user = $request->user();

        $input = $request->validate([
            'entity' => ['nullable', 'string', 'max:60'],
            'entity_id' => ['nullable', 'string', 'max:60'],
            'action' => ['nullable', 'string', 'max:60'],
            'category' => ['nullable', Rule::in(AuditRecorder::CATEGORIES)],
            'actor_id' => ['nullable', 'string', 'max:60'],
            'support_grant_id' => ['nullable', 'string', 'max:60'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'cursor' => ['nullable', 'string', 'regex:/^[a-f0-9]{24}$/'],
        ]);

        $all = $access->allows($user, 'audit.view');
        $academic = $access->allows($user, 'audit.view_academic');
        $own = $access->holdsAt($user, 'audit.view', 'own');

        if (! $all && ! $academic && ! $own) {
            throw new ApiException('permission_denied', 'You do not have permission to see the audit log.', 403);
        }

        $query = AuditLog::query();

        if (! $all) {
            $userId = (string) $user->getKey();

            match (true) {
                $academic && $own => $query->where(fn ($q) => $q->where('category', 'academic')->orWhere('actor_id', $userId)),
                $academic => $query->where('category', 'academic'),
                default => $query->where('actor_id', $userId),
            };
        }

        foreach (['entity', 'entity_id', 'action', 'category', 'support_grant_id'] as $field) {
            if (! empty($input[$field])) {
                $query->where($field, $input[$field]);
            }
        }

        if (! empty($input['actor_id'])) {
            $query->where('actor_id', $input['actor_id']);
        }

        if (! empty($input['from'])) {
            $query->where('at', '>=', now()->parse($input['from']));
        }

        if (! empty($input['to'])) {
            $query->where('at', '<=', now()->parse($input['to']));
        }

        if (! empty($input['cursor'])) {
            $query->where('_id', '<', new ObjectId($input['cursor']));
        }

        $entries = $query->orderBy('_id', 'desc')->limit(51)->get();
        $more = $entries->count() > 50;
        $entries = $entries->take(50);

        return response()->json([
            'data' => $entries->map(fn (AuditLog $e) => [
                'id' => (string) $e->getKey(),
                'at' => $e->at->toIso8601String(),
                'actor' => $e->actor,
                'support_grant_id' => $e->support_grant_id,
                'action' => $e->action,
                'category' => $e->category,
                'entity' => $e->entity,
                'entity_id' => $e->entity_id,
                'before' => $e->before,
                'after' => $e->after,
                'redacted' => $e->redacted,
                'meta' => $e->meta,
                'ip' => $e->ip,
                'device' => $e->device,
            ])->values(),
            'next_cursor' => $more ? (string) $entries->last()?->getKey() : null,
        ]);
    }
}
