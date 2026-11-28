<?php

namespace App\Modules\Assessment\Models;

use App\Modules\Identity\Domain\Scopes\AccessSubject;
use App\Support\Models\SchoolModel;
use Illuminate\Support\Carbon;

/**
 * One section x one subject x one assessment, with the marks embedded
 * (bounded: one section, about 60 students).
 *
 * Lifecycle: draft -> open -> submitted -> verified -> locked, then
 * published. `published_at` is set when students and parents may see it:
 * normally after lock. A class test with auto-publish on is published at
 * submit and stays editable until it is locked (owner's decision).
 *
 * A locked mark changes only through a correction the Principal approves
 * (CorrectionRequest). `version` is the ETag: every save of the marks must
 * send If-Match with it, so two people editing at once get a conflict
 * instead of an overwrite.
 *
 *   marks[]  {student_id, value: float|null, code: AB|ML|null, remark: string|null,
 *             updated_at?: ISO 8601, set when the mark changed after publishing}
 *
 * @property string $assessment_id
 * @property string $year_id
 * @property string $section_id
 * @property string $section_label
 * @property string $subject_id
 * @property string $subject_name
 * @property string|null $wing
 * @property bool $class_test
 * @property float $max_marks
 * @property string|null $date Y-m-d
 * @property string $status
 * @property int $version
 * @property list<array{student_id: string, value: float|null, code: string|null, remark: string|null}> $marks
 * @property Carbon|null $published_at
 * @property array<string, mixed>|null $locked
 * @property list<array<string, mixed>> $history
 * @property string|null $returned_note
 */
class Paper extends SchoolModel implements AccessSubject
{
    public const DRAFT = 'draft';

    public const OPEN = 'open';

    public const SUBMITTED = 'submitted';

    public const VERIFIED = 'verified';

    public const LOCKED = 'locked';

    protected $table = 'papers';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id', 'assessment_id', 'year_id', 'section_id', 'section_label', 'subject_id', 'subject_name', 'wing', 'class_test',
        'max_marks', 'date', 'status', 'version', 'marks', 'published_at', 'locked', 'history', 'returned_note',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::DRAFT, 'version' => 1, 'marks' => [], 'published_at' => null, 'locked' => null, 'history' => [],
        'class_test' => false, 'returned_note' => null, 'wing' => null,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['max_marks' => 'float', 'version' => 'integer', 'published_at' => 'datetime', 'class_test' => 'boolean'];
    }

    /**
     * The marks are audited one by one with old and new values (Markbook
     * records "marks.changed"); the version and history are bookkeeping.
     *
     * @return list<string>
     */
    public function auditIgnored(): array
    {
        return ['updated_at', 'version', 'marks', 'history'];
    }

    public function accessFacts(): array
    {
        return ['section_id' => $this->section_id, 'wing' => $this->wing, 'subject_key' => $this->section_id.':'.$this->subject_id];
    }

    public function isLocked(): bool
    {
        return $this->status === self::LOCKED;
    }

    public function etag(): string
    {
        return '"v'.$this->version.'"';
    }

    /**
     * @return array<string, array{student_id: string, value: float|null, code: string|null, remark: string|null}>
     */
    public function marksByStudent(): array
    {
        $out = [];
        foreach ($this->marks as $mark) {
            $out[$mark['student_id']] = $mark;
        }

        return $out;
    }
}
