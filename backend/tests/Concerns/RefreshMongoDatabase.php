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
 *   2. drops the whole test database before each test,
 *   3. runs the migrations again, which recreate collections and indexes.
 *
 * Use it in a test file with:  uses(RefreshMongoDatabase::class);
 * Laravel calls setUpRefreshMongoDatabase() automatically for any trait.
 */
trait RefreshMongoDatabase
{
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

        $connection->getDatabase()->drop();

        $this->app[Kernel::class]->call('migrate', ['--force' => true]);
    }
}
