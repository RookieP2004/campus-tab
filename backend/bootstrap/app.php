<?php

use App\Modules\Identity\Http\Middleware\EnsureMfaEnrolled;
use App\Modules\Identity\Http\Middleware\RequirePermission;
use App\Modules\Identity\Http\Middleware\RequireStepUp;
use App\Modules\Identity\Http\Middleware\TrackDeviceSession;
use App\Modules\Licensing\Http\Middleware\EnsureLicenceAllows;
use App\Modules\Licensing\Http\Middleware\EnsureModuleEnabled;
use App\Modules\Platform\Http\Middleware\ConsoleAuthenticate;
use App\Modules\Platform\Http\Middleware\ConsoleStepUp;
use App\Modules\Tenancy\Http\Middleware\SetTenantFromUser;
use App\Support\Http\ApiExceptionRenderer;
use App\Support\Http\AppOriginCors;
use App\Support\Http\Idempotency;
use App\Support\Http\SecurityHeaders;
use App\Support\Privacy\RefuseFullAadhaar;
use App\Support\Uploads\ScanUploadedFiles;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api/v1',
    )
    // Artisan commands live in each module's Console/ folder.
    ->withCommands(glob(__DIR__.'/../app/Modules/*/Console', GLOB_ONLYDIR) ?: [])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        // The apps' web views: bearer token only, never cookies (Phase 9).
        $middleware->prepend(AppOriginCors::class);
        // CSP, frame-ancestors, Referrer-Policy, HSTS in production only (Phase 9).
        $middleware->append(SecurityHeaders::class);

        $middleware->alias([
            'tenant' => SetTenantFromUser::class,
            'device' => TrackDeviceSession::class,
            'mfa.enrolled' => EnsureMfaEnrolled::class,
            'licence' => EnsureLicenceAllows::class,
            'module' => EnsureModuleEnabled::class,
            'permission' => RequirePermission::class,
            'step-up' => RequireStepUp::class,
            // The platform console (session cookie, guard "console").
            'console' => ConsoleAuthenticate::class,
            'console.step-up' => ConsoleStepUp::class,
            // Full Aadhaar numbers are refused in any field (decision 0011).
            'no-aadhaar' => RefuseFullAadhaar::class,
            // Idempotency-Key on creates and workflow actions (Phase 5).
            'idempotent' => Idempotency::class,
        ]);

        // enrol:   an enrol-only session may use these (MFA set-up, sign-out).
        // account: the user's own account; works on a read-only licence.
        // school:  school data. A platform admin passes only with a support
        //          token for an active grant (see SetTenantFromUser). Each
        //          route adds permission:<ability> and, if sensitive, step-up:<action>.
        $middleware->group('enrol', ['auth:sanctum', 'tenant', 'device']);
        $middleware->group('account', ['auth:sanctum', 'tenant', 'device', 'mfa.enrolled']);
        // scan-uploads: every file is virus-scanned before a controller sees it (Phase 9).
        $middleware->group('school', ['auth:sanctum,platform', 'tenant', 'device', 'mfa.enrolled', 'licence', 'no-aadhaar', ScanUploadedFiles::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        ApiExceptionRenderer::register($exceptions);
    })->create();
