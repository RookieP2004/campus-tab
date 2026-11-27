<?php

namespace App\Modules\System\Domain;

use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;
use MongoDB\Laravel\Connection as MongoConnection;
use Throwable;

/**
 * Checks the services every request depends on.
 *
 * - db:    sends a "ping" command to MongoDB on the default connection.
 * - queue: asks the default queue connection (Redis) for the size of its
 *          default queue, which proves the queue store answers.
 *
 * Errors are logged but never returned, so the public endpoint leaks no
 * host names or credentials.
 */
class HealthCheck
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly QueueFactory $queue,
    ) {}

    /**
     * @return array{status: 'ok'|'fail', db: 'ok'|'fail', queue: 'ok'|'fail'}
     */
    public function run(): array
    {
        $db = $this->check('db', function (): void {
            $connection = $this->db->connection();

            if (! $connection instanceof MongoConnection) {
                throw new \RuntimeException('The default database connection is not MongoDB.');
            }

            $connection->getDatabase()->command(['ping' => 1]);
        });

        $queue = $this->check('queue', function (): void {
            $this->queue->connection()->size();
        });

        return [
            'status' => $db === 'ok' && $queue === 'ok' ? 'ok' : 'fail',
            'db' => $db,
            'queue' => $queue,
        ];
    }

    /**
     * @param  callable(): void  $probe
     * @return 'ok'|'fail'
     */
    private function check(string $name, callable $probe): string
    {
        try {
            $probe();

            return 'ok';
        } catch (Throwable $e) {
            Log::warning("Health check failed: {$name}", ['exception' => $e::class, 'message' => $e->getMessage()]);

            return 'fail';
        }
    }
}
