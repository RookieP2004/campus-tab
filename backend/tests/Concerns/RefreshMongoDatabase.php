<?php

namespace Tests\Concerns;

use Illuminate\Contracts\Console\Kernel;
use MongoDB\Laravel\Connection as MongoConnection;
use RuntimeException;

/**
 * Gives every test a clean MongoDB database.
 *
 * Laravel's RefreshDatabase trait relies on SQL transactions and does not
 * work with MongoDB, so this trait instead:
 *   1. refuses to run unless the database name ends in "_test" (so it can
 *      never wipe a development or production database),
 *   2. the first time in each test process: drops the whole database and
 *      runs the migrations, which create the collections and indexes,
 *   3. before every later test: empties the collections the migrations
 *      made (keeping their indexes) and drops any other collection a test
 *      created. This is much faster than rebuilding every index each time.
 *
 * In a parallel run (`composer test` uses `pest --parallel`) each process
 * of each run has its own database, campus_tab_<n>_<run>_test (see
 * Tests\TestCase); it is dropped when the process ends, and the first
 * migrate also drops the databases of runs that are no longer alive.
 *
 * Use it in a test file with:  uses(RefreshMongoDatabase::class);
 * Laravel calls setUpRefreshMongoDatabase() automatically for any trait.
 */
trait RefreshMongoDatabase
{
    /** @var array<string, list<string>> collections made by the migrations, per database */
    private static array $migratedCollections = [];

    public function setUpRefreshMongoDatabase(): void
    {
        $connection = $this->app['db']->connection();

        if (! $connection instanceof MongoConnection) {
            throw new RuntimeException('RefreshMongoDatabase needs the default connection to be MongoDB.');
        }

        $name = $connection->getDatabaseName();

        if (! str_ends_with($name, '_test')) {
            throw new RuntimeException(
                "Refusing to drop database [{$name}]: test database names must end in \"_test\". Check MONGODB_DATABASE in phpunit.xml."
            );
        }

        $database = $connection->getDatabase();

        if (! isset(self::$migratedCollections[$name])) {
            $database->drop();
            if (preg_match('/^(.+)_\d+_(\d+)_test$/', $name, $m) === 1) {
                self::dropDeadRuns($connection, $m[1]);
                register_shutdown_function(static function () use ($database): void {
                    try {
                        $database->drop();
                    } catch (\Throwable) {
                        // The server may already be gone; the next run cleans up.
                    }
                });
            }
            $this->app[Kernel::class]->call('migrate', ['--force' => true]);

            $collections = [];
            foreach ($database->listCollectionNames() as $collection) {
                $collections[] = $collection;
            }
            self::$migratedCollections[$name] = $collections;

            return;
        }

        $known = self::$migratedCollections[$name];

        foreach ($database->listCollectionNames() as $collection) {
            if ($collection === 'migrations') {
                continue;
            }

            if (in_array($collection, $known, true)) {
                $database->selectCollection($collection)->deleteMany([]);
            } else {
                $database->dropCollection($collection);
            }
        }
    }

    /** Drops campus_tab_<n>_<run>_test databases whose run (a pid) has ended. */
    private static function dropDeadRuns(MongoConnection $connection, string $prefix): void
    {
        if (! is_dir('/proc')) {
            return;
        }
        foreach ($connection->getClient()->listDatabaseNames() as $db) {
            if (preg_match('/^'.preg_quote($prefix, '/').'_\d+_(\d+)_test$/', $db, $m) === 1 && ! is_dir('/proc/'.$m[1])) {
                $connection->getClient()->dropDatabase($db);
            }
        }
    }
}
