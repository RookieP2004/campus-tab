<?php

use App\Modules\Tenancy\Domain\SchoolConfigService;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Tests\Concerns\CreatesSchools;
use Tests\Concerns\RefreshMongoDatabase;

uses(RefreshMongoDatabase::class, CreatesSchools::class);

/*
| Item 3: GET /public/tenants/{code}/config returns public branding only.
*/

it('returns public branding and nothing else', function () {
    $school = $this->activeSchool('SUNRISE-BPL');

    $response = $this->getJson('/api/v1/public/tenants/SUNRISE-BPL/config')
        ->assertOk()
        ->assertHeader('Cache-Control', 'max-age=300, public');

    expect(array_keys($response->json('data')))->toEqualCanonicalizing(['code', 'name', 'short_name', 'logo_url', 'accent', 'languages', 'login_methods', 'grievance'])
        ->and($response->json('data.code'))->toBe('SUNRISE-BPL')
        ->and($response->json('data.name'))->toBe($school['tenant']->name)
        ->and($response->json('data.accent'))->toBe('#0F766E')
        ->and($response->json('data.languages'))->toBe(['default' => 'en', 'featured' => ['en', 'hi'], 'all' => config('identity.languages')])
        ->and($response->json('data.login_methods'))->toBe(['password', 'parent_otp']);

    $raw = $response->getContent();
    foreach (['tenant_id', 'policies', 'modules', 'contact', 'licence', 'office@', $school['tenant']->id] as $private) {
        expect($raw)->not->toContain($private);
    }
});

it('accepts the code in any case', function () {
    $this->activeSchool('SUNRISE-BPL');

    $this->getJson('/api/v1/public/tenants/sunrise-bpl/config')->assertOk()->assertJsonPath('data.code', 'SUNRISE-BPL');
});

it('shows the latest branding after the Chief changes it', function () {
    $school = $this->activeSchool('SUNRISE-BPL');
    $body = $this->configBody($school['tenant']);
    $body['branding']['accent'] = '#1D4ED8';
    app(TenantContext::class)->run($school['tenant']->id, fn () => app(SchoolConfigService::class)->save($school['chief'], $body, 1));

    $this->getJson('/api/v1/public/tenants/SUNRISE-BPL/config')->assertJsonPath('data.accent', '#1D4ED8');
});

it('returns 404 for an unknown, pending or suspended school', function () {
    $this->pendingSchool('PENDING-BPL');
    $suspended = $this->activeSchool('CLOSED-BPL');
    $suspended['tenant']->forceFill(['status' => Tenant::STATUS_SUSPENDED])->save();

    foreach (['NOPE-XYZ', 'PENDING-BPL', 'CLOSED-BPL'] as $code) {
        $this->getJson("/api/v1/public/tenants/{$code}/config")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'tenant_not_found');
    }
});
