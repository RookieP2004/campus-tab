<?php

use App\Modules\Identity\Http\Middleware\EnsureMfaEnrolled;
use App\Modules\Identity\Http\Middleware\RequirePermission;
use App\Modules\Identity\Http\Middleware\RequireStepUp;
use App\Modules\Identity\Http\Middleware\TrackDeviceSession;
use App\Modules\Licensing\Http\Middleware\EnsureLicenceAllows;
use App\Modules\Licensing\Http\Middleware\EnsureModuleEnabled;
use App\Modules\Tenancy\Http\Middleware\SetTenantFromUser;
use App\Support\Http\ApiExceptionRenderer;
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

        $middleware->alias([
            'tenant' => SetTenantFromUser::class,
            'device' => TrackDeviceSession::class,
            'mfa.enrolled' => EnsureMfaEnrolled::class,
            'licence' => EnsureLicenceAllows::class,
            'module' => EnsureModuleEnabled::class,
            'permission' => RequirePermission::class,
            'step-up' => RequireStepUp::class,
        ]);

        // enrol:   an enrol-only session may use these (MFA set-up, sign-out).
        // account: the user's own account; works on a read-only licence.
        // school:  school data. A platform admin passes only with a support
        //          token for an active grant (see SetTenantFromUser). Each
        //          route adds permission:<ability> and, if sensitive, step-up:<action>.
        $middleware->group('enrol', ['auth:sanctum', 'tenant', 'device']);
        $middleware->group('account', ['auth:sanctum', 'tenant', 'device', 'mfa.enrolled']);
        $middleware->group('school', ['auth:sanctum,platform', 'tenant', 'device', 'mfa.enrolled', 'licence']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        ApiExceptionRenderer::register($exceptions);
    })->create();
