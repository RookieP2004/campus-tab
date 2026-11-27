<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Domain\SchoolConfigService;
use App\Modules\Tenancy\Models\SchoolConfig;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Http\ApiException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * The school logo (public branding).
 *
 *   POST /config/logo (file)                  PNG, JPEG or WebP, up to 512 KB; sets school.logo_url
 *   GET  /public/tenants/{code}/logo          the logo, for the sign-in screen and the brand stack
 *
 * Stored privately under tenants/{tenant_id}/branding/ and served by the
 * API, so no storage link is needed. SVG is refused (it can carry script).
 */
class SchoolLogoController
{
    public function store(Request $request, SchoolConfigService $configs, TenantContext $context): JsonResponse
    {
        Gate::authorize('view', SchoolConfig::class);

        $request->validate(['file' => ['required', 'file', 'max:512', 'mimes:png,jpg,jpeg,webp', 'dimensions:max_width=2048,max_height=2048']]);

        $user = $request->user();
        if (! $user instanceof User) {
            throw new ApiException('school_account_required', 'Sign in with a school account to continue.', 403);
        }

        $file = $request->file('file');
        $extension = $file?->extension() === 'jpeg' ? 'jpg' : (string) $file?->extension();
        $dir = 'tenants/'.$context->require().'/branding';
        Storage::disk('local')->deleteDirectory($dir);
        $file?->storeAs($dir, 'logo.'.$extension, 'local');

        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');
        $current = $configs->current();
        $body = $current->body;
        $body['school']['logo_url'] = '/api/v1/public/tenants/'.$tenant->code.'/logo?v='.substr(hash_file('sha256', (string) $file?->getRealPath()) ?: '', 0, 12);

        // Checked like any config change: only those who may edit the school section.
        $saved = $configs->save($user, $body, $current->version, 'School logo');

        return response()->json(['data' => ['logo_url' => $saved->body['school']['logo_url'], 'version' => $saved->version]], 201);
    }

    public function show(string $code): Response
    {
        $tenant = Tenant::findActiveByCode($code) ?? throw new ApiException('not_found', 'No school has this code.', 404);
        $disk = Storage::disk('local');

        foreach (['png', 'jpg', 'webp'] as $extension) {
            $path = 'tenants/'.$tenant->id.'/branding/logo.'.$extension;

            if ($disk->exists($path)) {
                return response()->file($disk->path($path), [
                    'Cache-Control' => 'public, max-age=86400',
                    'X-Content-Type-Options' => 'nosniff',
                ]);
            }
        }

        throw new ApiException('not_found', 'This school has no logo yet.', 404);
    }
}
