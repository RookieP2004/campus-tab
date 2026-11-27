<?php

namespace App\Support\Tenancy;

use Illuminate\Support\Facades\Log;
use MongoDB\BSON\Document;
use MongoDB\Driver\Monitoring\CommandFailedEvent;
use MongoDB\Driver\Monitoring\CommandStartedEvent;
use MongoDB\Driver\Monitoring\CommandSubscriber;
use MongoDB\Driver\Monitoring\CommandSucceededEvent;

/**
 * Watches every command the MongoDB driver sends and records any read or
 * write on a school collection that does not filter by tenant_id.
 *
 * It is fail-closed: every collection counts as a school collection unless
 * it is listed in config('tenancy.platform_collections'). Commands run inside
 * TenantContext::bypass() are allowed (the documented sign-in exception).
 *
 * In tests (mode "record") each violation is kept and Tests\TestCase fails the
 * test at tear-down, so CI fails if any code path skips the tenant filter.
 * In development (mode "log") violations are written to the log.
 */
class TenantQueryGuard implements CommandSubscriber
{
    /** Commands that read or write documents, and where their filter lives. */
    private const FILTER_COMMANDS = [
        'find' => 'filter',
        'count' => 'query',
        'distinct' => 'query',
        'findAndModify' => 'query',
    ];

    /** @var list<array{collection: string, command: string, reason: string}> */
    private static array $violations = [];

    private static ?self $instance = null;

    /**
     * One guard per PHP process. The driver shares its connection between
     * MongoDB client objects, so a new subscriber per application boot would
     * pile up; adding the same instance again is a no-op. Settings are read
     * from the current application at each command.
     */
    public static function instance(): self
    {
        return self::$instance ??= new self;
    }

    public function commandStarted(CommandStartedEvent $event): void
    {
        $mode = (string) config('tenancy.query_guard', 'log');

        if ($mode === 'off' || $event->getDatabaseName() !== config('database.connections.mongodb.database')) {
            return;
        }

        $name = $event->getCommandName();
        $tracked = isset(self::FILTER_COMMANDS[$name]) || in_array($name, ['aggregate', 'update', 'delete', 'insert'], true);

        if (! $tracked || app(TenantContext::class)->bypassing()) {
            return;
        }

        /** @var array<string, mixed> $command */
        $command = Document::fromPHP($event->getCommand())->toPHP(['root' => 'array', 'document' => 'array', 'array' => 'array']);
        $collection = $command[$name] ?? null;

        if (! is_string($collection) || $this->isPlatform($collection)) {
            return;
        }

        $reason = $this->findProblem($name, $command);

        if ($reason !== null) {
            $this->report($collection, $name, $reason);
        }
    }

    public function commandSucceeded(CommandSucceededEvent $event): void {}

    public function commandFailed(CommandFailedEvent $event): void {}

    /**
     * @return list<array{collection: string, command: string, reason: string}>
     */
    public static function violations(): array
    {
        return self::$violations;
    }

    public static function flush(): void
    {
        self::$violations = [];
    }

    private function isPlatform(string $collection): bool
    {
        return str_starts_with($collection, 'system.') || in_array($collection, (array) config('tenancy.platform_collections', []), true);
    }

    /**
     * @param  array<string, mixed>  $command
     */
    private function findProblem(string $name, array $command): ?string
    {
        if (isset(self::FILTER_COMMANDS[$name])) {
            $filter = $command[self::FILTER_COMMANDS[$name]] ?? [];

            return self::hasTenantFilter($filter) ? null : 'filter has no tenant_id';
        }

        return match ($name) {
            'aggregate' => self::pipelineStartsWithTenantMatch($command['pipeline'] ?? []) ? null : 'pipeline does not start with a tenant_id $match',
            'update' => self::allHaveTenant($command['updates'] ?? [], 'q') ? null : 'update filter has no tenant_id',
            'delete' => self::allHaveTenant($command['deletes'] ?? [], 'q') ? null : 'delete filter has no tenant_id',
            'insert' => self::allDocumentsHaveTenant($command['documents'] ?? []) ? null : 'inserted document has no tenant_id',
            default => null,
        };
    }

    /**
     * True when the filter pins tenant_id at the top level or inside a
     * top-level $and (how the query builder combines several wheres).
     */
    public static function hasTenantFilter(mixed $filter): bool
    {
        if (! is_array($filter)) {
            return false;
        }

        if (array_key_exists('tenant_id', $filter) && $filter['tenant_id'] !== null) {
            return true;
        }

        foreach ((array) ($filter['$and'] ?? []) as $part) {
            if (self::hasTenantFilter($part)) {
                return true;
            }
        }

        return false;
    }

    private static function pipelineStartsWithTenantMatch(mixed $pipeline): bool
    {
        return is_array($pipeline) && isset($pipeline[0]['$match']) && self::hasTenantFilter($pipeline[0]['$match']);
    }

    private static function allHaveTenant(mixed $statements, string $key): bool
    {
        if (! is_array($statements)) {
            return false;
        }

        foreach ($statements as $statement) {
            if (! is_array($statement) || ! self::hasTenantFilter($statement[$key] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private static function allDocumentsHaveTenant(mixed $documents): bool
    {
        if (! is_array($documents)) {
            return false;
        }

        foreach ($documents as $document) {
            if (! is_array($document) || ! is_string($document['tenant_id'] ?? null) || $document['tenant_id'] === '') {
                return false;
            }
        }

        return true;
    }

    private function report(string $collection, string $command, string $reason): void
    {
        $violation = compact('collection', 'command', 'reason');

        if (config('tenancy.query_guard') === 'record') {
            self::$violations[] = $violation;

            return;
        }

        Log::warning('Tenant query guard: a school-data query ran without tenant_id.', $violation);
    }
}
