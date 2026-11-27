<?php

namespace App\Modules\Leave\Models;

use App\Modules\Identity\Domain\Scopes\AccessSubject;
use App\Support\Models\SchoolModel;
use App\Support\Privacy\SensitiveValue;
use Illuminate\Support\Carbon;

/**
 * A leave application: a staff member's own leave, or a parent's request
 * for their child (kind "student").
 *
 * States (LeaveWorkflow holds the legal moves):
 *   draft      saved, not sent
 *   submitted  with the first stage (the Clerk, or the decider for own-leave routing)
 *   returned   sent back to the applicant to fix and resubmit
 *   verified   checked at stage 1, with the next stage
 *   approved   decided; balance deducted, attendance shows On leave
 *   rejected   decided against, with a reason
 *   withdrawn  taken back by the applicant before a decision
 *   cancelled  an approved leave called off; untaken days restored
 *
 * Privacy (owner's rule): the reason, every remark and the documents are
 * seen only by the applicant and the people in this request's chain. The
 * reason and remarks are encrypted at rest (SensitiveValue), documents are
 * stored encrypted, and the audit log keeps only that they changed.
 *
 * @property string $kind staff | student
 * @property array{user_id: string|null, name: string, staff_id?: string|null, student_id?: string|null, level: int, wing: string|null} $applicant
 * @property array{id: string, name: string} $applied_by
 * @property string|null $applicant_user_id top-level copies for queries
 * @property string|null $applicant_staff_id
 * @property string|null $applicant_student_id
 * @property string|null $applied_by_id
 * @property string|null $section_id student leave: the child's section
 * @property string|null $wing
 * @property string|null $type_code
 * @property string|null $type_name
 * @property string|null $year_id
 * @property string $from Y-m-d
 * @property string $to Y-m-d
 * @property string|null $half_day first | second | null
 * @property float $days
 * @property mixed $reason sealed
 * @property list<array{id: string, name: mixed, mime: string, size: int, path: string, at: string}> $documents
 * @property string $status
 * @property string|null $stage clerk | vice_principal | principal | chief | class_teacher
 * @property list<string> $chain
 * @property list<array<string, mixed>> $steps
 * @property float $reserved_days
 * @property array{from: string, to: string, days: float}|null $approved
 * @property array<string, mixed>|null $cancel_request
 * @property Carbon|null $stage_since
 * @property string|null $reminded_for
 * @property string|null $escalated_for
 * @property list<array{date: string, section_id: string, section: string, subject: string}> $cover_needed
 * @property bool $principal_sees student leave longer than the school's limit
 * @property bool $fast_tracked
 */
class LeaveRequest extends SchoolModel implements AccessSubject
{
    public const DRAFT = 'draft';

    public const SUBMITTED = 'submitted';

    public const RETURNED = 'returned';

    public const VERIFIED = 'verified';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const WITHDRAWN = 'withdrawn';

    public const CANCELLED = 'cancelled';

    /** Waiting on someone in the chain. */
    public const PENDING = [self::SUBMITTED, self::VERIFIED];

    protected $table = 'leave_requests';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id', 'kind', 'applicant', 'applicant_user_id', 'applicant_staff_id', 'applicant_student_id', 'applied_by', 'applied_by_id', 'section_id', 'wing',
        'type_code', 'type_name', 'year_id', 'from', 'to', 'half_day', 'days', 'reason', 'documents', 'status', 'stage', 'chain', 'steps',
        'reserved_days', 'approved', 'cancel_request', 'stage_since', 'reminded_for', 'escalated_for', 'cover_needed', 'principal_sees', 'fast_tracked',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'kind' => 'staff', 'documents' => [], 'steps' => [], 'chain' => [], 'reserved_days' => 0.0, 'approved' => null, 'cancel_request' => null,
        'reminded_for' => null, 'escalated_for' => null, 'cover_needed' => [], 'principal_sees' => false, 'fast_tracked' => false, 'half_day' => null,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['days' => 'float', 'reserved_days' => 'float', 'stage_since' => 'datetime', 'principal_sees' => 'boolean', 'fast_tracked' => 'boolean'];
    }

    public function auditCategory(): string
    {
        return $this->kind === 'student' ? 'academic' : 'admin';
    }

    /**
     * The audit log keeps that the reason, remarks or documents changed, never
     * what they say (the log is read by people outside the chain).
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function auditSnapshot(array $values): array
    {
        if (array_key_exists('reason', $values)) {
            $values['reason'] = '[private]';
        }
        if (array_key_exists('documents', $values)) {
            $values['documents'] = ['count' => count((array) $values['documents'])];
        }
        if (array_key_exists('steps', $values)) {
            $values['steps'] = array_map(function ($step) {
                $step = (array) $step;
                if (($step['remark'] ?? null) !== null) {
                    $step['remark'] = '[private]';
                }

                return $step;
            }, (array) $values['steps']);
        }
        if (is_array($values['cancel_request'] ?? null)) {
            $values['cancel_request']['reason'] = '[private]';
        }

        return $values;
    }

    public function accessFacts(): array
    {
        return ['section_id' => $this->section_id, 'wing' => $this->wing, 'student_id' => $this->applicant['student_id'] ?? null];
    }

    public function isPending(): bool
    {
        return in_array($this->status, self::PENDING, true);
    }

    public function plainReason(): ?string
    {
        $value = SensitiveValue::open($this->reason);

        return is_string($value) ? $value : null;
    }

    public function applicantUserId(): ?string
    {
        return $this->applicant['user_id'] ?? null;
    }
}
