<?php

namespace App\Modules\Privacy\Models;

use App\Support\Models\SchoolModel;

/**
 * One retention check for one kind of data. It starts as a dry run: how
 * many records are past their time, the oldest and newest dates, and the
 * cut-off. Nothing is deleted until the Chief confirms it (step-up
 * permanent_delete); then exactly the records listed in the dry run (by
 * id, still past the cut-off) are removed for good and the count is kept.
 *
 * @property string $kind a key of privacy.retention
 * @property string $status dry_run | confirmed | expired
 * @property int $days
 * @property string $cutoff ISO time
 * @property int $count
 * @property list<string> $ids
 * @property string|null $oldest
 * @property string|null $newest
 * @property array{id: string, name: string}|null $made_by null when the daily job made it
 * @property array{id: string, name: string}|null $confirmed_by
 * @property string|null $confirmed_at
 * @property int|null $deleted
 */
class RetentionRun extends SchoolModel
{
    protected $table = 'retention_runs';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'kind', 'status', 'days', 'cutoff', 'count', 'ids', 'oldest', 'newest', 'made_by', 'confirmed_by', 'confirmed_at', 'deleted'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'dry_run', 'made_by' => null, 'confirmed_by' => null, 'confirmed_at' => null, 'deleted' => null];

    /** @var list<string> */
    protected $hidden = ['ids'];

    public function auditCategory(): string
    {
        return 'security';
    }

    /** @return list<string> */
    public function auditIgnored(): array
    {
        return ['updated_at', 'ids'];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['days' => 'integer', 'count' => 'integer', 'deleted' => 'integer'];
    }
}
