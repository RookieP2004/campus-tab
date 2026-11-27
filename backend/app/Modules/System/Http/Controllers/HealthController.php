<?php

namespace App\Modules\System\Http\Controllers;

use App\Modules\System\Domain\HealthCheck;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/health
 *
 * Public, no sign-in. Returns {"status":"ok","db":"ok","queue":"ok"} with
 * HTTP 200 when MongoDB and the Redis queue both answer, or HTTP 503 with
 * "status":"fail" and the failing part marked "fail".
 */
class HealthController
{
    public function __invoke(HealthCheck $health): JsonResponse
    {
        $result = $health->run();

        return response()->json($result, $result['status'] === 'ok' ? 200 : 503);
    }
}
