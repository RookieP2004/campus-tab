<?php

namespace App\Modules\Identity\Console;

use App\Modules\Audit\Domain\AuditRecorder;
use App\Modules\Identity\Domain\SystemRoles;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Adds any missing system roles to existing schools (new schools get them
 * when they are created). With --with-new-permissions it also adds the
 * permissions a later phase introduced; ones a Chief removed stay removed.
 */
class SyncRolesCommand extends Command
{
    protected $signature = 'identity:sync-roles
        {code? : one school code; all schools when left out}
        {--with-new-permissions : also give system roles the permissions added since they were seeded}';

    protected $description = 'Add missing system roles (config/permissions.php) to existing schools';

    public function handle(TenantContext $context, AuditRecorder $audit): int
    {
        $code = $this->argument('code');
        $tenants = is_string($code) ? array_filter([Tenant::findByCode($code)]) : Tenant::query()->get()->all();

        if ($tenants === []) {
            $this->error('No school found.');

            return self::FAILURE;
        }

        foreach ($tenants as $tenant) {
            $created = $context->run($tenant->id, function () use ($audit): int {
                $created = AuditRecorder::muted(fn () => SystemRoles::seed());

                if ($created > 0) {
                    $audit->record('roles.seeded', 'admin', 'roles', null, meta: ['created' => $created]);
                }

                return $created;
            });

            $this->line("{$tenant->code}: {$created} role(s) added.");

            if ($this->option('with-new-permissions')) {
                $added = $context->run($tenant->id, function () use ($audit): array {
                    $added = AuditRecorder::muted(fn () => SystemRoles::addNewPermissions());
                    if ($added !== []) {
                        $audit->record('roles.permissions_added', 'admin', 'roles', null, meta: ['added' => $added]);
                    }

                    return $added;
                });

                foreach ($added as $role => $permissions) {
                    $this->line("  {$role}: +".count($permissions).' permission(s)');
                }

                // Abilities the code retired (e.g. marks.unlock in Phase 6) leave every role.
                $removed = $context->run($tenant->id, function () use ($audit): array {
                    $removed = AuditRecorder::muted(fn () => SystemRoles::dropRetiredPermissions());
                    if ($removed !== []) {
                        $audit->record('roles.permissions_retired', 'admin', 'roles', null, meta: ['removed' => $removed]);
                    }

                    return $removed;
                });

                foreach ($removed as $role => $permissions) {
                    $this->line("  {$role}: -".implode(', ', $permissions));
                }
            }
        }

        return self::SUCCESS;
    }
}
