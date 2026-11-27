<?php

namespace Tests;

use App\Modules\Licensing\Domain\LicenceSigner;
use App\Support\Tenancy\TenantQueryGuard;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** @var array{secret_key: string, public_key: string}|null one test key pair per run */
    private static ?array $licenceKeys = null;

    /**
     * In a parallel run (pest --parallel) every process gets its own
     * database: campus_tab_test becomes campus_tab_<n>_test. The name still
     * ends in _test, so RefreshMongoDatabase's safety check holds.
     *
     * The name is set before the application boots, never by purging the
     * connection afterwards: the tenant query guard subscribes to the
     * connection made at boot, and a new connection would silently lack it.
     */
    public function createApplication()
    {
        $token = $_SERVER['TEST_TOKEN'] ?? getenv('TEST_TOKEN');
        $base = (string) ($_SERVER['MONGODB_DATABASE'] ?? getenv('MONGODB_DATABASE') ?: 'campus_tab_test');

        if (((is_string($token) && $token !== '') || is_int($token)) && preg_match('/^(.+)_test$/', $base, $m) === 1 && ! str_ends_with($m[1], '_'.$token)) {
            $name = $m[1].'_'.$token.'_test';
            $_SERVER['MONGODB_DATABASE'] = $_ENV['MONGODB_DATABASE'] = $name;
            putenv('MONGODB_DATABASE='.$name);
        }

        return parent::createApplication();
    }

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
