<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Domain\PublicConfig;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Http\ApiException;
use Illuminate\Http\JsonResponse;

/**
 * GET /public/tenants/{code}/config: branding for the sign-in screen.
 * Anyone may call it; it returns public branding only, and only for active
 * schools (an unknown, pending or suspended code is a 404).
 */
class PublicConfigController
{
    public function show(string $code, PublicConfig $publicConfig): JsonResponse
    {
        $tenant = Tenant::findActiveByCode($code)
            ?? throw new ApiException('tenant_not_found', 'No school uses this code. Check the code with your school office.', 404);

        return response()
            ->json(['data' => $publicConfig->for($tenant)])
            ->header('Cache-Control', 'public, max-age=300');
    }
}
