<?php

namespace Tests;

use App\Modules\Licensing\Domain\LicenceSigner;
use App\Support\Tenancy\TenantQueryGuard;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** @var array{secret_key: string, public_key: string}|null one test key pair per run */
    private static ?array $licenceKeys = null;

    protected function setUp(): void
    {
        parent::setUp();

        TenantQueryGuard::flush();

        // Test-only licence keys, generated per run; real ones live in .env.
        self::$licenceKeys ??= LicenceSigner::generateKeyPair();
        config([
            'licensing.signing.secret_key' => self::$licenceKeys['secret_key'],
            'licensing.signing.public_key' => self::$licenceKeys['public_key'],
            'licensing.key_pepper' => str_repeat('test-pepper-', 4),
        ]);
    }

    /**
     * The tenant query guard records every school-data query sent without
     * tenant_id. Any such query fails the test that caused it, so CI fails
     * when a code path skips the tenant filter.
     */
    protected function assertPostConditions(): void
    {
        $violations = TenantQueryGuard::violations();
        TenantQueryGuard::flush();

        $this->assertSame([], $violations, 'A query on school data ran without tenant_id (see TenantQueryGuard).');

        parent::assertPostConditions();
    }
}
