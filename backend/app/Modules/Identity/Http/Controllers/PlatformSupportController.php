<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Domain\SupportGrants;
use App\Modules\Identity\Models\PlatformAdmin;
use App\Support\Http\ApiException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Our side of support access (platform admins only).
 *
 *   POST /platform/support/sessions { school_code }
 *
 * Returns a token for that one school, valid until the grant ends, only
 * when the school's Chief has granted this admin access. Without an active
 * grant: 403 support_grant_required. Platform sign-in itself arrives with
 * the console in Phase 1 part C.
 */
class PlatformSupportController
{
    public function open(Request $request, SupportGrants $grants, TenantContext $context): JsonResponse
    {
        $admin = $request->user();

        if (! $admin instanceof PlatformAdmin || $admin->status !== 'active' || ! $admin->tokenCan('platform')) {
            throw new ApiException('platform_account_required', 'Sign in with a Campus TAB platform account.', 403);
        }

        $input = $request->validate(['school_code' => ['required', 'string', 'max:30']]);
        $session = $grants->openSession($admin, $input['school_code']);

        return response()->json(['data' => [
            'token' => $session['token'],
            'token_type' => 'Bearer',
            'expires_at' => $session['expires_at'],
            'school' => $session['school'],
            'grant' => $context->run($session['grant']->tenant_id, fn () => SupportGrantController::present($session['grant'])),
        ]], 201);
    }
}
