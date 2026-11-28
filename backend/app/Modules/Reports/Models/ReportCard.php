<?php

namespace App\Modules\Reports\Models;

use App\Modules\Identity\Domain\Scopes\AccessSubject;
use App\Support\Models\SchoolModel;
use Illuminate\Support\Carbon;

/**
 * One version of one student's yearly report card.
 *
 *   draft -> reviewed -> published, and a published version becomes
 *   "replaced" when a re-issue publishes the next version. A version is
 *   never edited after publishing: the snapshot (the computed result, the
 *   remarks, the grades, the attendance, frozen when it was published) and
 *   its PDF stay as they were.
 *
 *   snapshot       the grading engine's result from LOCKED papers only
 *   remarks        {class_teacher: string|null, subjects: {subject_id: string}}
 *   co_scholastic  {area key: grade}
 *   pdf            {path, size, sha256} under tenants/<tenant>/report-cards/
 *   verify_hash    SHA-256 of the QR code's random code (the code itself is
 *                  only in the PDF)
 *
 * @property string $year_id
 * @property string $year_name
 * @property string $student_id
 * @property string $student_name
 * @property string|null $student_user_id the student's own sign-in, if any
 * @property string $section_id
 * @property string $section_label
 * @property string|null $wing
 * @property string $template_id
 * @property string $scheme_id
 * @property int $version
 * @property string $status draft | reviewed | published | replaced
 * @property array<string, mixed> $snapshot
 * @property array{class_teacher?: string|null, subjects?: array<string, string>} $remarks
 * @property array<string, string> $co_scholastic
 * @property array{present_halves: int, working: int}|null $attendance
 * @property array{path: string, size: int, sha256: string}|null $pdf
 * @property string|null $verify_hash
 * @property Carbon|null $issued_at
 * @property array{id: string, name: string}|null $published_by
 * @property string|null $reissue_reason
 * @property string|null $replaces_id
 * @property string|null $replaced_by_id
 * @property list<array<string, mixed>> $history
 */
class ReportCard extends SchoolModel implements AccessSubject
{
    public const DRAFT = 'draft';

    public const REVIEWED = 'reviewed';

    public const PUBLISHED = 'published';

    public const REPLACED = 'replaced';

    protected $table = 'report_cards';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id', 'year_id', 'year_name', 'student_id', 'student_name', 'student_user_id', 'section_id', 'section_label', 'wing', 'template_id', 'scheme_id',
        'version', 'status', 'snapshot', 'remarks', 'co_scholastic', 'attendance', 'pdf', 'verify_hash', 'issued_at', 'published_by',
        'reissue_reason', 'replaces_id', 'replaced_by_id', 'history',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'version' => 1, 'status' => self::DRAFT, 'snapshot' => [], 'remarks' => [], 'co_scholastic' => [], 'attendance' => null, 'pdf' => null,
        'verify_hash' => null, 'issued_at' => null, 'published_by' => null, 'reissue_reason' => null, 'replaces_id' => null, 'replaced_by_id' => null,
        'history' => [], 'wing' => null, 'student_user_id' => null,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['version' => 'integer', 'issued_at' => 'datetime'];
    }

    /**
     * The snapshot and remarks are private academic records; the audit log
     * keeps who changed what state, not the marks themselves (the marks are
     * already audited one by one in the markbook).
     *
     * @return list<string>
     */
    public function auditIgnored(): array
    {
        return ['updated_at', 'snapshot', 'remarks', 'history', 'verify_hash', 'pdf'];
    }

    public function accessFacts(): array
    {
        return [
            'section_id' => $this->section_id,
            'wing' => $this->wing,
            'student_ids' => [$this->student_id],
            'owner_ids' => is_string($this->student_user_id) ? [$this->student_user_id] : [],
        ];
    }

    public function isPublic(): bool
    {
        return in_array($this->status, [self::PUBLISHED, self::REPLACED], true);
    }
}
