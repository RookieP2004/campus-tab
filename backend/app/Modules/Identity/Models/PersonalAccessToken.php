<?php

namespace App\Modules\Identity\Models;

use App\Support\Tenancy\TenantContext;
use Laravel\Sanctum\PersonalAccessToken as SanctumToken;
use MongoDB\Laravel\Eloquent\DocumentModel;

/**
 * Sanctum's API token, stored in MongoDB (personal_access_tokens).
 *
 * Tokens are looked up before the school is known, so this is a platform
 * collection (config/tenancy.php). Loading the token's owner is the
 * documented exception to the tenant scope: it runs inside
 * TenantContext::bypass(), and the `tenant` middleware then sets the school
 * from that user's tenant_id (docs/report/decisions/0005).
 *
 * @property list<string>|null $abilities
 */
class PersonalAccessToken extends SanctumToken
{
    use DocumentModel;

    protected $connection = 'mongodb';

    protected $table = 'personal_access_tokens';

    protected $keyType = 'string';

    /**
     * @param  string  $key
     */
    public function getRelationValue($key): mixed
    {
        if ($key === 'tokenable' && ! $this->relationLoaded('tokenable')) {
            return app(TenantContext::class)->bypass(
                'sign-in: load the owner of an API token',
                fn () => parent::getRelationValue($key),
            );
        }

        return parent::getRelationValue($key);
    }
}
