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
    // Platform accounts only (auth:platform); the school side is checked per grant
    'POST api/v1/platform/support/sessions',
];

/**
 * @return list<RouteDefinition>
 */
function apiRoutes(): array
{
    return array_values(array_filter(
        Route::getRoutes()->getRoutes(),
        fn (RouteDefinition $r) => str_starts_with($r->uri(), 'api/v1') && ! str_starts_with($r->uri(), 'api/v1/_probe'),
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
