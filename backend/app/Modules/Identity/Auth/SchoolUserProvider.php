<?php

namespace App\Modules\Identity\Auth;

use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use SensitiveParameter;

/**
 * Loads school users for the session guard (the SPA's sign-in cookie).
 *
 * retrieveById() and retrieveByToken() run before the school is known: the
 * session holds only the user's id. They are the documented exception to the
 * tenant scope and run inside TenantContext::bypass(). The `tenant`
 * middleware then sets the school from that user's tenant_id.
 *
 * retrieveByCredentials() is NOT an exception: password sign-in first finds
 * the school from its code and runs inside that school
 * (see Domain\SchoolUserLocator).
 */
class SchoolUserProvider extends EloquentUserProvider
{
    public function retrieveById($identifier): ?Authenticatable
    {
        return app(TenantContext::class)->bypass(
            'sign-in: load the user of a session',
            fn () => parent::retrieveById($identifier),
        );
    }

    public function retrieveByToken($identifier, #[SensitiveParameter] $token): ?Authenticatable
    {
        return app(TenantContext::class)->bypass(
            'sign-in: load the user of a remember-me cookie',
            fn () => parent::retrieveByToken($identifier, $token),
        );
    }
}
