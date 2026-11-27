<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Audit\Domain\PlatformAuditRecorder;
use App\Modules\Platform\Models\PlatformAlert;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Security alerts for our team (counts only).
 *
 *   GET  /platform/alerts ?all=1           open alerts (all with all=1), newest first
 *   POST /platform/alerts/{id}/acknowledge
 */
class ConsoleAlertController
{
    public function index(Request $request): JsonResponse
    {
        $query = PlatformAlert::query()->orderBy('raised_at', 'desc')->limit(200);

        if (! $request->boolean('all')) {
            $query->whereNull('acknowledged_at');
        }

        return response()->json(['data' => $query->get()->map(fn (PlatformAlert $a) => $a->present())->values()]);
    }

    public function acknowledge(Request $request, string $id, PlatformAuditRecorder $audit): JsonResponse
    {
        $admin = ConsoleAuthController::admin($request);
        $alert = PlatformAlert::query()->findOrFail($id);

        if ($alert->acknowledged_at === null) {
            $alert->forceFill(['acknowledged_at' => now(), 'acknowledged_by' => ['id' => (string) $admin->getKey(), 'name' => $admin->name]])->save();
            $audit->record('alert.acknowledged', $admin, Tenant::query()->find($alert->school_id), 'platform_alerts', $id, meta: ['metric' => $alert->metric]);
        }

        return response()->json(['data' => $alert->present()]);
    }
}
