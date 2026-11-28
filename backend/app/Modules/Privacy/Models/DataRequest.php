<?php

namespace App\Modules\Privacy\Models;

use App\Support\Models\SchoolModel;

/**
 * A data request under the DPDP Act: access (a copy of what we hold),
 * correction, or erasure. Made from the person's own account: a parent
 * (about themselves or their child), a student or a staff member. Each has
 * a due date; the Chief and the Principal work the inbox.
 *
 * @property string $type access | correction | erasure
 * @property string $status received | in_progress | completed | rejected
 * @property array{id: string, name: string, role: string} $requested_by
 * @property string $requester_id the same id at the top level (nested ids cannot be queried)
 * @property string $subject_type self | student
 * @property string|null $student_id when the request is about a child
 * @property string|null $subject_name
 * @property string $details what the person asked, in their words
 * @property string $due_on Y-m-d
 * @property array{id: string, name: string}|null $handled_by
 * @property string|null $outcome what was done, or why it was refused (shown to the requester)
 * @property string|null $completed_at
 * @property list<array{at: string, by: string, note: string}> $history
 */
class DataRequest extends SchoolModel
{
    public const TYPES = ['access', 'correction', 'erasure'];

    public const OPEN = ['received', 'in_progress'];

    protected $table = 'data_requests';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'type', 'status', 'requested_by', 'requester_id', 'subject_type', 'student_id', 'subject_name', 'details', 'due_on', 'handled_by', 'outcome', 'completed_at', 'history'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'received', 'handled_by' => null, 'outcome' => null, 'completed_at' => null, 'history' => []];

    public function auditCategory(): string
    {
        return 'security';
    }
}
