<?php

namespace App\Modules\Assessment\Models;

use App\Support\Models\SchoolModel;
use Illuminate\Support\Carbon;

/**
 * A request to change a locked mark. Anyone holding
 * marks.correction_request in scope may raise it (Clerk, teachers, the
 * Chief, and the Principal); only the Principal approves it, from their own
 * account, with a fresh MFA code and a typed reason. The record keeps the
 * old and new mark, the reason, who asked, who decided and when.
 *
 * self_approved: the Principal raised it and then approved it (a separate
 * step, with MFA entered after raising it); shown to the Chief.
 *
 * @property string $paper_id
 * @property string $assessment_id
 * @property string $student_id
 * @property string $student_name
 * @property string $section_id
 * @property string $label e.g. "Science 8-A · Unit Test 1"
 * @property array{value: float|null, code: string|null} $old
 * @property array{value: float|null, code: string|null} $new
 * @property string $reason
 * @property array{id: string, name: string, role: string|null} $requested_by
 * @property string $requested_by_id
 * @property string $status pending | approved | rejected
 * @property array{id: string, name: string}|null $decided_by
 * @property string|null $decision_reason
 * @property Carbon|null $decided_at
 * @property bool $self_approved
 */
class CorrectionRequest extends SchoolModel
{
    protected $table = 'correction_requests';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id', 'paper_id', 'assessment_id', 'student_id', 'student_name', 'section_id', 'label', 'old', 'new', 'reason',
        'requested_by', 'requested_by_id', 'status', 'decided_by', 'decision_reason', 'decided_at', 'self_approved',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'pending', 'decided_by' => null, 'decision_reason' => null, 'decided_at' => null, 'self_approved' => false];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['decided_at' => 'datetime', 'self_approved' => 'boolean'];
    }
}
