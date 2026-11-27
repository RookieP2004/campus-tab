<?php

namespace App\Support\Models;

use App\Modules\Audit\Domain\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Support\Carbon;
use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Eloquent\SoftDeletes;

/**
 * Base for a school record: tenant-scoped (BelongsToTenant), every write in
 * the audit log (Auditable), soft deletes to the recycle bin. Every school
 * collection's indexes start with tenant_id.
 *
 * @property string $id
 * @property string $tenant_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
abstract class SchoolModel extends Model
{
    use Auditable, BelongsToTenant, SoftDeletes;

    protected $connection = 'mongodb';

    /** academic | admin | security | fees (see AuditRecorder). */
    public function auditCategory(): string
    {
        return 'academic';
    }

    /**
     * @return list<string>
     */
    public function auditIgnored(): array
    {
        return ['updated_at'];
    }
}
