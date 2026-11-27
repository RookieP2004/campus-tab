<?php

namespace App\Modules\People\Models;

use App\Support\Models\SchoolModel;

/**
 * One Excel import. status: queued, validating, validated (dry run done,
 * report ready), committing, committed, or failed. The commit writes every
 * record under this batch id in the audit log.
 *
 * @property string $entity students | staff
 * @property string $status
 * @property string $file_path private storage path
 * @property string $file_name
 * @property array{done: int, total: int} $progress
 * @property array<string, mixed>|null $summary
 * @property list<array{row: int, status: string, label: string, messages: list<string>}> $rows
 * @property array{id: string, name: string} $created_by
 * @property array{created: int}|null $result
 * @property string|null $error
 */
class ImportBatch extends SchoolModel
{
    public const STATUSES = ['queued', 'validating', 'validated', 'committing', 'committed', 'failed'];

    protected $table = 'import_batches';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'entity', 'status', 'file_path', 'file_name', 'progress', 'summary', 'rows', 'created_by', 'result', 'error'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'queued', 'progress' => ['done' => 0, 'total' => 0], 'rows' => [], 'summary' => null, 'result' => null, 'error' => null];

    /**
     * @return list<string>
     */
    public function auditIgnored(): array
    {
        // Progress ticks and the report are not changes worth an entry.
        return ['updated_at', 'progress', 'rows', 'summary', 'status', 'result', 'error'];
    }
}
