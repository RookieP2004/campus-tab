<?php

use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Domain\SchoolUserLocator;
use App\Modules\Identity\Models\PersonalAccessToken;
use App\Modules\Identity\Models\User;
use App\Support\Tenancy\MissingTenantContext;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantQueryGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\CreatesSchools;
use Tests\Concerns\RefreshMongoDatabase;

uses(RefreshMongoDatabase::class, CreatesSchools::class);

/*
| The one documented exception to the tenant scope (decision 0005): sign-in
| and token lookup happen before the school is known.
|
|  - Token path: the token and its user load without the scope, then the
|    `tenant` middleware sets TenantContext from that user's tenant_id.
|  - Session path: the session guard loads the user by id the same way.
|  - Password path: the school is found from its code first, then the user
|    is looked up inside that school only.
*/

beforeEach(function () {
    $this->a = $this->activeSchool('ALPHA-BPL');
    $this->b = $this->activeSchool('BETA-IDR');

    Route::middleware(['auth:sanctum', 'tenant'])->get('/api/v1/_probe/whoami', fn () => [
        'tenant' => app(TenantContext::class)->id(),
        'user' => (string) auth()->id(),
    ]);
});

describe('token path', function () {
    it('loads the token and its user before the school is known, then sets the school from the user', function () {
        $this->actingWithToken($this->b['chief'])
            ->getJson('/api/v1/_probe/whoami')
            ->assertOk()
            ->assertExactJson(['tenant' => $this->b['tenant']->id, 'user' => (string) $this->b['chief']->getKey()]);

        // The context is cleared again after the request.
        expect(app(TenantContext::class)->id())->toBeNull();
    });

    it('finds a token with no tenant set, and the guard treats tokens as platform data', function () {
        $plain = $this->tokenFor($this->a['chief']);

        $token = PersonalAccessToken::findToken($plain);
        $owner = $token?->tokenable;

        expect($owner)->toBeInstanceOf(User::class)
            ->and($owner->tenant_id)->toBe($this->a['tenant']->id)
            ->and(TenantQueryGuard::violations())->toBe([]);
    });

    it('rejects a request with an unknown token', function () {
        $this->withToken('1|not-a-real-token')->getJson('/api/v1/_probe/whoami')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthenticated');
    });
});

describe('session path', function () {
    it('loads the session user by id without a tenant, through the school-users provider', function () {
        $provider = Auth::createUserProvider('users');

        $user = $provider->retrieveById($this->b['chief']->getKey());

        expect($user)->toBeInstanceOf(User::class)
            ->and($user->tenant_id)->toBe($this->b['tenant']->id)
            ->and(TenantQueryGuard::violations())->toBe([]);
    });
});

describe('password path', function () {
    it('finds the school from its code, then the user inside that school', function () {
        $inA = $this->schoolUser($this->a['tenant'], Role::Clerk, email: 'clerk@shared.test');
        $inB = $this->schoolUser($this->b['tenant'], Role::Clerk, email: 'clerk@shared.test');

        [$tenant, $user] = app(SchoolUserLocator::class)->find('beta-idr', 'Clerk@Shared.test');

        expect($tenant->id)->toBe($this->b['tenant']->id)
            ->and((string) $user->getKey())->toBe((string) $inB->getKey())
            ->and((string) $user->getKey())->not->toBe((string) $inA->getKey());
    });

    it('finds a user by phone inside the school', function () {
        $clerk = $this->schoolUser($this->a['tenant'], Role::Clerk);

        [, $user] = app(SchoolUserLocator::class)->find('ALPHA-BPL', $clerk->phone);

        expect((string) $user->getKey())->toBe((string) $clerk->getKey());
    });

    it('does not find a user of another school', function () {
        $this->schoolUser($this->a['tenant'], Role::Clerk, email: 'only-in-alpha@school.test');

        expect(app(SchoolUserLocator::class)->find('BETA-IDR', 'only-in-alpha@school.test'))->toBeNull();
    });

    it('finds nothing for an unknown or not yet active school', function () {
        $this->pendingSchool('GAMMA-UJN');

        expect(app(SchoolUserLocator::class)->find('NOPE-XYZ', 'anyone@school.test'))->toBeNull()
            ->and(app(SchoolUserLocator::class)->find('GAMMA-UJN', 'office@gamma-ujn.test'))->toBeNull();
    });

    it('never runs a credentials lookup without a school', function () {
        Auth::createUserProvider('users')->retrieveByCredentials(['email' => 'clerk@shared.test']);
    })->throws(MissingTenantContext::class);
});
