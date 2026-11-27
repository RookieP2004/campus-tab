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
     * @return array{code: string, name: string, short_name: string, logo_url: string|null, accent: string, languages: array{default: string, available: list<string>, user_can_choose: bool}, login_methods: list<string>}
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
                'available' => array_values($body['locale']['available_languages'] ?? ['en']),
                'user_can_choose' => (bool) ($body['locale']['user_can_choose_language'] ?? true),
            ],
            'login_methods' => array_values($body['sign_in']['methods'] ?? ['password']),
        ];
    }
}
