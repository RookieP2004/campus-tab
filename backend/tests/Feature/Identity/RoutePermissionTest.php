<?php

use App\Modules\Identity\Domain\PermissionCatalogue;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;

/*
| Permission checks run on the server for every endpoint. Every API route
| either names a permission (permission:<ability>), or is on this short list
| of routes that act only on the caller's own account or come before
| sign-in. Adding a route without either fails this test.
*/

const OPEN_ROUTES = [
    // Before sign-in
    'GET api/v1/health',
    'GET api/v1/public/tenants/{code}/config',
    'GET api/v1/public/tenants/{code}/logo',
    'POST api/v1/licences/activate/otp',
    'POST api/v1/licences/activate',
    'POST api/v1/auth/login',
    'POST api/v1/auth/mfa/verify',
    'POST api/v1/auth/otp/request',
    'POST api/v1/auth/otp/verify',
    'POST api/v1/auth/password/forgot',
    'POST api/v1/auth/password/set',
    // The caller's own account and devices
    'POST api/v1/auth/logout',
    'POST api/v1/auth/step-up',
    'GET api/v1/me',
    'PATCH api/v1/me/preferences',
    'PUT api/v1/me/password',
    'GET api/v1/me/sessions',
    'DELETE api/v1/me/sessions/{id}',
    'POST api/v1/me/sessions/revoke-others',
    'POST api/v1/me/mfa/enrol',
    'POST api/v1/me/mfa/enrol/confirm',
    'POST api/v1/me/mfa/recovery-codes',
    'DELETE api/v1/me/mfa',
    // Phase 3: my dashboard (cards built from what I may see), search, notifications
    'GET api/v1/me/dashboard',
    'GET api/v1/me/search',
    'GET api/v1/me/notifications',
    'POST api/v1/me/notifications/{id}/read',
    'POST api/v1/me/notifications/read-all',
    'GET api/v1/me/notification-preferences',
    'PUT api/v1/me/notification-preferences',
    'POST api/v1/me/push-devices',
    'DELETE api/v1/me/push-devices',
    // Reverb channel auth: the channel callback checks it is my own channel
    'GET api/v1/broadcasting/auth',
    // A notice attachment, only through a short-lived signed link
    'GET api/v1/files/notices/{tenant}/{notice}/{attachment}',
    // A late entry's paper-register photo, only through a short-lived signed link
    'GET api/v1/files/attendance/{tenant}/{day}',
    // A leave document, decrypted, only through a short-lived signed link
    'GET api/v1/files/leave/{tenant}/{request}/{doc}',
    // A report card PDF, only through a short-lived signed link (Phase 7)
    'GET api/v1/files/report-cards/{tenant}/{card}',
    // The public page a report card's QR code opens: identity facts only, no marks (Phase 7)
    'GET api/v1/public/verify/{school}/{code}',
    // The payment gateway's callback: no signed-in user; the signature on the raw body is the check (Phase 8, decision 0015)
    'POST api/v1/webhooks/payments/{gateway}',
    // Platform accounts only (auth:platform); the school side is checked per grant
    'POST api/v1/platform/support/sessions',
];

/*
| The platform console (our team, no school data) has its own guard: every
| console route after sign-in runs behind `console` (session, MFA, idle
| limit), and none of them is a school route.
*/
const CONSOLE_OPEN_ROUTES = [
    'POST api/v1/platform/auth/login',
    'POST api/v1/platform/auth/mfa/verify',
    'POST api/v1/platform/auth/password/set',
];

function isConsoleRoute(RouteDefinition $r): bool
{
    return str_starts_with($r->uri(), 'api/v1/platform/') && $r->uri() !== 'api/v1/platform/support/sessions';
}

/**
 * School and public API routes (the console is checked separately).
 *
 * @return list<RouteDefinition>
 */
function apiRoutes(): array
{
    return array_values(array_filter(
        Route::getRoutes()->getRoutes(),
        fn (RouteDefinition $r) => str_starts_with($r->uri(), 'api/v1') && ! str_starts_with($r->uri(), 'api/v1/_probe') && ! isConsoleRoute($r),
    ));
}

function routeKey(RouteDefinition $route): string
{
    return collect($route->methods())->reject(fn ($m) => $m === 'HEAD')->first().' '.$route->uri();
}

it('gives every API route a permission check, except the listed self-service and public ones', function () {
    $unchecked = [];

    foreach (apiRoutes() as $route) {
        $hasPermission = collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'permission:'));

        if (! $hasPermission) {
            $unchecked[] = routeKey($route);
        }
    }

    sort($unchecked);
    $expected = OPEN_ROUTES;
    sort($expected);

    expect($unchecked)->toBe($expected);
});

it('names only permissions the catalogue knows', function () {
    foreach (apiRoutes() as $route) {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'permission:')) {
                foreach (explode(',', substr($middleware, strlen('permission:'))) as $ability) {
                    expect(PermissionCatalogue::knowsAbility($ability))->toBeTrue(routeKey($route).': '.$ability);
                }
            }
        }
    }
});

it('puts every school-data route behind the school middleware (tenant, device, MFA, licence)', function () {
    foreach (apiRoutes() as $route) {
        if (! in_array(routeKey($route), OPEN_ROUTES, true)) {
            expect(in_array('school', $route->gatherMiddleware(), true))->toBeTrue(routeKey($route).' is not in the school group');
        }
    }
});

it('puts every console route behind the console middleware, and none in the school group', function () {
    $console = array_filter(Route::getRoutes()->getRoutes(), fn (RouteDefinition $r) => isConsoleRoute($r));

    expect($console)->not->toBeEmpty();

    foreach ($console as $route) {
        $middleware = $route->gatherMiddleware();

        expect(in_array('school', $middleware, true))->toBeFalse(routeKey($route).' must not be a school route');

        if (! in_array(routeKey($route), CONSOLE_OPEN_ROUTES, true)) {
            expect(in_array('console', $middleware, true))->toBeTrue(routeKey($route).' is not behind the console middleware');
        }
    }
});

it('asks for console step-up before revoking a licence or recovering a sole Chief', function () {
    $needs = [
        'POST api/v1/platform/schools/{code}/licence/revoke' => 'console.step-up:licence_revoke',
        'POST api/v1/platform/schools/{code}/chief-recovery' => 'console.step-up:chief_recovery',
        'POST api/v1/platform/schools/{code}/chief-recovery/complete' => 'console.step-up:chief_recovery',
    ];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (isset($needs[routeKey($route)])) {
            expect($route->gatherMiddleware())->toContain($needs[routeKey($route)]);
            unset($needs[routeKey($route)]);
        }
    }

    expect($needs)->toBe([]);
});
