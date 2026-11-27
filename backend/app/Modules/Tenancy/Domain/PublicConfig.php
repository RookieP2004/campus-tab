<?php

namespace App\Modules\Tenancy\Domain;

use App\Modules\Tenancy\Models\SchoolConfig;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantContext;

/**
 * What the sign-in screen may know about a school before anyone signs in.
 * Built from an explicit allow-list, so a new private setting can never leak
 * through this endpoint by accident.
 */
class PublicConfig
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @return array{code: string, name: string, short_name: string, logo_url: string|null, accent: string, languages: array{default: string, featured: list<string>, all: list<string>}, login_methods: list<string>}
     */
    public function for(Tenant $tenant): array
    {
        $body = $this->context->run($tenant->id, function () use ($tenant): array {
            /** @var SchoolConfig|null $config */
            $config = SchoolConfig::query()->where('version', $tenant->config_version)->first();

            return $config === null ? [] : $config->body;
        });

        return [
            'code' => $tenant->code,
            'name' => (string) ($body['school']['name'] ?? $tenant->name),
            'short_name' => (string) ($body['school']['short_name'] ?? $tenant->code),
            'logo_url' => $body['school']['logo_url'] ?? null,
            'accent' => (string) ($body['branding']['accent'] ?? '#0F766E'),
            'languages' => [
                'default' => (string) ($body['locale']['default_language'] ?? 'en'),
                'featured' => array_values($body['locale']['featured_languages'] ?? ['en']),
                // Every user may pick any language the app ships.
                'all' => array_values((array) config('identity.languages')),
            ],
            'login_methods' => array_values($body['sign_in']['methods'] ?? ['password']),
        ];
    }
}
