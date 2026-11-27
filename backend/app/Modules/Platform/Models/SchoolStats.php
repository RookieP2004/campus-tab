<?php

namespace App\Modules\Platform\Models;

use Illuminate\Support\Carbon;
use MongoDB\Laravel\Eloquent\Model;

/**
 * Numbers about one school, worked out by the scheduled job inside the
 * school (TenantContext::run) and kept here on the platform side. Numbers
 * only: no names, no record content. The console reads these instead of
 * the school's data, which needs a support grant.
 *
 * @property string $id
 * @property string $school_id
 * @property Carbon $computed_at
 * @property array{students: int, staff: int, active_7d: int, active_30d: int, last_activity_at: string|null, storage_bytes: int} $usage
 * @property array<string, array<string, int>> $security counts per window (last_hour, 24h, 7d, 30d) per metric
 * @property list<array<string, mixed>> $grants support grants: our agent, scope, times, status (no reasons, no school names)
 */
class SchoolStats extends Model
{
    protected $connection = 'mongodb';

    protected $table = 'school_stats';

    /** @var list<string> */
    protected $fillable = ['school_id', 'computed_at', 'usage', 'security', 'grants'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['computed_at' => 'datetime'];
    }
}
