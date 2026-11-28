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
     * In a parallel run (pest --parallel) every process gets a token that is
     * unique to this RUN, not just to the process: "<n>_<run>", where <run>
     * is the pid of the pest process that started the workers. Laravel builds
     * the cache prefix and Storage::fake() folders from the token, and the
     * database name is built from it here: campus_tab_test becomes
     * campus_tab_<n>_<run>_test (still ending in _test, so
     * RefreshMongoDatabase's safety check holds).
     *
     * Why the run id: two runs at once (a second terminal, an editor's test
     * runner, or workers left behind by a run that timed out) used to share
     * campus_tab_<n>_test, so one run emptied the other's collections mid-test
     * and tests failed now and then, only in parallel runs. RefreshMongoDatabase
     * drops a run's databases when its process ends, and the databases of
     * runs that are no longer alive.
     *
     * The name is set before the application boots, never by purging the
     * connection afterwards: the tenant query guard subscribes to the
     * connection made at boot, and a new connection would silently lack it.
     */
    public function createApplication()
    {
        $token = self::parallelToken();
        $base = (string) ($_SERVER['MONGODB_DATABASE'] ?? getenv('MONGODB_DATABASE') ?: 'campus_tab_test');

        if ($token !== null && preg_match('/^(.+)_test$/', $base, $m) === 1 && ! str_ends_with($m[1], '_'.$token)) {
            $name = $m[1].'_'.$token.'_test';
            $_SERVER['MONGODB_DATABASE'] = $_ENV['MONGODB_DATABASE'] = $name;
            putenv('MONGODB_DATABASE='.$name);

            // Redis is not used by tests today (cache, queue and sessions are in
            // memory); should a test switch to it, each run still has its own keys.
            $_SERVER['REDIS_PREFIX'] = $_ENV['REDIS_PREFIX'] = 'campus-tab-test-'.$token.'-';
            putenv('REDIS_PREFIX=campus-tab-test-'.$token.'-');
        }

        return parent::createApplication();
    }

    /**
     * "<n>_<run>" in a parallel run, null otherwise. Rewrites TEST_TOKEN once
     * per process, so Laravel's ParallelTesting (cache prefix, fake disks)
     * uses the same run-unique token.
     */
    public static function parallelToken(): ?string
    {
        $token = $_SERVER['TEST_TOKEN'] ?? getenv('TEST_TOKEN');
        if (! is_string($token) && ! is_int($token)) {
            return null;
        }
        $token = (string) $token;
        if ($token === '') {
            return null;
        }
        if (preg_match('/^\d+$/', $token) === 1) {
            $token .= '_'.(function_exists('posix_getppid') ? posix_getppid() : getmypid());
            $_SERVER['TEST_TOKEN'] = $_ENV['TEST_TOKEN'] = $token;
            putenv('TEST_TOKEN='.$token);
        }

        return $token;
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
