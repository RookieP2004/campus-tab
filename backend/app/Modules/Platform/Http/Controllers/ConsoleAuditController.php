<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Audit\Models\PlatformAuditLog;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The platform audit log, newest first.
 *
 *   GET /platform/audit-logs ?school=<code> &before=<ISO time>
 */
class ConsoleAuditController
{
    public function index(Request $request): JsonResponse
    {
        $query = PlatformAuditLog::query()->orderBy('at', 'desc');
        $code = trim((string) $request->query('school', ''));

        if ($code !== '') {
            $query->where('school_id', Tenant::findByCode($code)->id ?? '-');
        }

        $before = $request->query('before');

        if (is_string($before) && strtotime($before) !== false) {
            $query->where('at', '<', now()->setTimestamp((int) strtotime($before)));
        }

        $entries = $query->limit(100)->get();

        return response()->json([
            'data' => $entries->map(fn (PlatformAuditLog $e) => [
                'id' => (string) $e->getKey(),
                'at' => $e->at->toIso8601String(),
                'actor' => $e->actor,
                'action' => $e->action,
                'school_code' => $e->school_code,
                'entity' => $e->entity,
                'entity_id' => $e->entity_id,
                'before' => $e->before,
                'after' => $e->after,
                'meta' => $e->meta,
                'ip' => $e->ip,
            ])->values(),
            'next_before' => $entries->count() === 100 ? $entries->last()?->at->toIso8601String() : null,
        ]);
    }
}
