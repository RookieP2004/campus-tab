<?php

use App\Modules\Identity\Http\Middleware\EnsureMfaEnrolled;
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

        // School routes: auth:sanctum, tenant, mfa.enrolled, licence, then module:<name>.
        $middleware->alias([
            'tenant' => SetTenantFromUser::class,
            'mfa.enrolled' => EnsureMfaEnrolled::class,
            'licence' => EnsureLicenceAllows::class,
            'module' => EnsureModuleEnabled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        ApiExceptionRenderer::register($exceptions);
    })->create();
