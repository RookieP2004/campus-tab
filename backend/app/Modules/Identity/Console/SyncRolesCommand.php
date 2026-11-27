<?php

namespace App\Modules\Identity\Console;

use App\Modules\Audit\Domain\AuditRecorder;
use App\Modules\Identity\Domain\SystemRoles;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Adds any missing system roles to existing schools (new schools get them
 * when they are created). Roles a Chief has edited are left alone.
 */
class SyncRolesCommand extends Command
{
    protected $signature = 'identity:sync-roles {code? : one school code; all schools when left out}';

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
        }

        return self::SUCCESS;
    }
}
