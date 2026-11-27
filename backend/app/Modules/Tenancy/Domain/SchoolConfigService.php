<?php

namespace App\Modules\Tenancy\Domain;

use App\Modules\Identity\Models\User;
use App\Modules\Licensing\Models\Licence;
use App\Modules\Tenancy\Models\SchoolConfig;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Http\ApiException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Reads and writes the current school's versioned config.
 *
 * Every save: checks the caller saw the latest version (If-Match), validates
 * the body against the JSON schema, refuses modules the licence does not
 * include, works out the diff, asks SchoolConfigPolicy whether this user may
 * change every changed path, then writes a new version and moves the
 * school's pointer in one transaction. Rollback is a save of an old body.
 */
class SchoolConfigService
{
    public function __construct(
        private readonly ConfigSchema $schema,
        private readonly TenantContext $context,
    ) {}

    public function current(): SchoolConfig
    {
        $tenant = $this->tenant();

        return $this->version($tenant->config_version);
    }

    public function version(int $version): SchoolConfig
    {
        /** @var SchoolConfig|null $config */
        $config = SchoolConfig::query()->where('version', $version)->first();

        return $config ?? throw new ApiException('config_version_not_found', "Version {$version} of the school settings does not exist.", 404);
    }

    /**
     * @return Collection<int, SchoolConfig>
     */
    public function history(int $limit = 100): Collection
    {
        return SchoolConfig::query()
            ->orderBy('version', 'desc')
            ->limit($limit)
            ->get(['version', 'diff', 'changed_by', 'reason', 'rolled_back_from', 'created_at']);
    }

    /**
     * Writes version 1 when a school is activated. Runs inside that school.
     *
     * @param  list<string>  $licensedModules
     */
    public function initialise(Tenant $tenant, array $licensedModules, User $actor): SchoolConfig
    {
        return $this->context->run($tenant->id, function () use ($tenant, $licensedModules, $actor): SchoolConfig {
            $body = DefaultConfig::for($tenant, $licensedModules);
            $this->assertValid($body);

            $config = SchoolConfig::create([
                'version' => 1,
                'body' => $body,
                'diff' => [],
                'changed_by' => self::actor($actor),
                'reason' => 'School activated',
                'rolled_back_from' => null,
            ]);

            Tenant::query()->where('_id', $tenant->id)->update(['config_version' => 1]);

            return $config;
        });
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function save(User $actor, array $body, int $expectedVersion, ?string $reason = null, ?int $rolledBackFrom = null): SchoolConfig
    {
        $current = $this->current();

        if ($current->version !== $expectedVersion) {
            throw new ApiException(
                'config_conflict',
                'Someone else changed the school settings. Reload to see their changes, then try again.',
                412,
                ['current_version' => $current->version],
            );
        }

        $this->assertValid($body);
        $this->assertModulesLicensed($body);

        $diff = ConfigDiff::between($current->body, $body);

        if ($diff === []) {
            return $current;
        }

        Gate::forUser($actor)->authorize('update', [SchoolConfig::class, ConfigDiff::paths($diff)]);

        return DB::connection('mongodb')->transaction(function () use ($actor, $body, $diff, $current, $reason, $rolledBackFrom): SchoolConfig {
            $next = $current->version + 1;

            $moved = Tenant::query()
                ->where('_id', $this->context->require())
                ->where('config_version', $current->version)
                ->update(['config_version' => $next]);

            if ($moved !== 1) {
                throw new ApiException('config_conflict', 'Someone else changed the school settings. Reload and try again.', 412);
            }

            return SchoolConfig::create([
                'version' => $next,
                'body' => $body,
                'diff' => $diff,
                'changed_by' => self::actor($actor),
                'reason' => $reason,
                'rolled_back_from' => $rolledBackFrom,
            ]);
        });
    }

    public function rollback(User $actor, int $toVersion, int $expectedVersion, ?string $reason = null): SchoolConfig
    {
        $target = $this->version($toVersion);

        return $this->save($actor, $target->body, $expectedVersion, $reason ?? "Rolled back to version {$toVersion}", $toVersion);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function assertValid(array $body): void
    {
        $errors = $this->schema->errors($body);

        if ($errors !== []) {
            throw new ApiException('config_invalid', 'Some school settings are not valid.', 422, ['errors' => $errors]);
        }
    }

    /**
     * A module can be switched on only if the licence includes it.
     *
     * @param  array<string, mixed>  $body
     */
    private function assertModulesLicensed(array $body): void
    {
        $licensed = Licence::currentFor($this->context->require())?->verifiedPayload()['modules'] ?? [];
        $wanted = array_keys(array_filter((array) ($body['modules'] ?? [])));
        $missing = array_values(array_diff($wanted, $licensed));

        if ($missing !== []) {
            throw new ApiException('module_not_licensed', 'These modules are not part of the licence: '.implode(', ', $missing).'.', 422, ['modules' => $missing]);
        }
    }

    private function tenant(): Tenant
    {
        /** @var Tenant $tenant */
        $tenant = Tenant::query()->findOrFail($this->context->require());

        return $tenant;
    }

    /**
     * @return array{id: string, name: string, level: int}
     */
    private static function actor(User $user): array
    {
        return ['id' => (string) $user->getKey(), 'name' => $user->name, 'level' => $user->level()];
    }
}
