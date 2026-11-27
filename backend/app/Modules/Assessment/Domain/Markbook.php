<?php

namespace App\Modules\Assessment\Domain;

use App\Modules\AcademicStructure\Models\Section;
use App\Modules\Assessment\Models\Paper;
use App\Modules\Attendance\Domain\AttendanceRegister;
use App\Modules\Audit\Domain\AuditRecorder;
use App\Modules\Identity\Models\User;
use App\Support\Http\ApiException;

/**
 * The markbook for one paper: the section's roll with each student's mark,
 * and saving changed cells.
 *
 *  - Online only (owner's rule): there is no offline entry; a save that
 *    fails is kept on screen until it goes through.
 *  - Every save sends If-Match with the paper's version. The write is
 *    conditional on that version, so when two people edit the same paper
 *    the second gets 412 paper_conflict (with the current marks) instead of
 *    overwriting the first.
 *  - A mark is a number from 0 to the paper's maximum in steps of 0.5, or a
 *    code (AB absent, ML medical), or empty. Anything else is refused.
 *  - Each changed mark is written to the audit log as its own entry with
 *    the old and new value (marks.changed).
 *  - Locked papers refuse every edit: a locked mark changes only through a
 *    correction the Principal approves.
 */
class Markbook
{
    public function __construct(
        private readonly PaperAccess $access,
        private readonly AttendanceRegister $register,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @return list<array{id: string, name: string, roll_no: int|null, admission_no: string}>
     */
    public function roll(Paper $paper): array
    {
        $section = Section::query()->find($paper->section_id);

        return $section instanceof Section ? $this->register->roll($section) : [];
    }

    /**
     * The roll with each mark (students who joined later start empty).
     *
     * @return list<array{student_id: string, name: string, roll_no: int|null, admission_no: string, value: float|null, code: string|null, remark: string|null}>
     */
    public function rows(Paper $paper): array
    {
        $marks = $paper->marksByStudent();

        return array_map(fn (array $s) => [
            'student_id' => $s['id'],
            'name' => $s['name'],
            'roll_no' => $s['roll_no'],
            'admission_no' => $s['admission_no'],
            'value' => isset($marks[$s['id']]['value']) ? (float) $marks[$s['id']]['value'] : null,
            'code' => $marks[$s['id']]['code'] ?? null,
            'remark' => $marks[$s['id']]['remark'] ?? null,
        ], $this->roll($paper));
    }

    /** May the marks be typed now? Open papers, and a published class test until it is locked. */
    public static function editable(Paper $paper): bool
    {
        return $paper->status === Paper::OPEN || ($paper->class_test && $paper->published_at !== null && $paper->status === Paper::SUBMITTED);
    }

    /**
     * @param  array<string, array{value?: mixed, code?: mixed, remark?: mixed}>  $changes  student id => cell
     */
    public function save(User $user, Paper $paper, int $expectedVersion, array $changes): Paper
    {
        if ($paper->isLocked()) {
            throw new ApiException('marks_locked', 'This paper is locked. A mark can change now only through a correction the Principal approves.', 409);
        }
        if (! self::editable($paper)) {
            throw new ApiException('paper_not_open', 'Marks can be entered only while the paper is open for entry.', 409, ['status' => $paper->status]);
        }
        if (! $this->access->mayEnter($user, $paper)) {
            throw new ApiException('permission_denied', 'You may not enter marks on this paper.', 403);
        }

        $roll = array_column($this->roll($paper), 'id');
        $current = $paper->marksByStudent();
        $changed = [];

        foreach ($changes as $studentId => $cell) {
            if (! in_array($studentId, $roll, true)) {
                throw new ApiException('student_not_in_section', 'One of the students is not in this section.', 422, ['student_id' => $studentId]);
            }

            $new = self::cell($cell, $paper->max_marks, (string) $studentId);
            $old = $current[$studentId] ?? ['student_id' => (string) $studentId, 'value' => null, 'code' => null, 'remark' => null];

            if (self::same($old, $new)) {
                continue;
            }

            $current[$studentId] = $new;
            $changed[$studentId] = ['old' => self::plain($old), 'new' => self::plain($new)];
        }

        if ($changed === []) {
            if ($paper->version !== $expectedVersion) {
                throw $this->conflict($paper);
            }

            return $paper;
        }

        $marks = array_values($current);

        // Written only if nobody saved in between (the version still matches).
        $updated = Paper::query()->where('_id', $paper->getKey())->where('version', $expectedVersion)->whereIn('status', [Paper::OPEN, Paper::SUBMITTED])
            ->update(['marks' => $marks, 'version' => $expectedVersion + 1, 'updated_at' => now()]);

        $fresh = Paper::query()->findOrFail($paper->getKey());

        if ($updated === 0) {
            throw $this->conflict($fresh);
        }

        foreach ($changed as $studentId => $values) {
            $this->audit->record('marks.changed', 'academic', 'paper', (string) $paper->getKey(), $values['old'], $values['new'], [
                'student_id' => $studentId, 'subject' => $paper->subject_name, 'section' => $paper->section_label,
            ]);
        }

        return $fresh;
    }

    /**
     * Checks one cell and returns the stored shape.
     *
     * @param  array{value?: mixed, code?: mixed, remark?: mixed}  $cell
     * @return array{student_id: string, value: float|null, code: string|null, remark: string|null}
     */
    public static function cell(array $cell, float $max, string $studentId): array
    {
        $code = isset($cell['code']) && $cell['code'] !== '' ? strtoupper(trim((string) $cell['code'])) : null;
        $raw = $cell['value'] ?? null;
        $remark = isset($cell['remark']) ? trim((string) $cell['remark']) : null;

        if (is_string($raw) && in_array(strtoupper(trim($raw)), array_keys((array) config('assessment.codes')), true)) {
            $code = strtoupper(trim($raw));
            $raw = null;
        }

        if ($code !== null && ! array_key_exists($code, (array) config('assessment.codes'))) {
            throw new ApiException('mark_code_invalid', 'Use AB (absent) or ML (medical) as codes.', 422, ['student_id' => $studentId]);
        }

        $value = null;
        if ($code === null && $raw !== null && $raw !== '') {
            if (! is_numeric($raw)) {
                throw new ApiException('mark_invalid', 'A mark must be a number, AB or ML.', 422, ['student_id' => $studentId]);
            }
            $value = (float) $raw;
            $step = (float) config('assessment.mark_step', 0.5);
            if ($value < 0 || $value > $max) {
                throw new ApiException('mark_above_max', "Marks must be from 0 to {$max}.", 422, ['student_id' => $studentId, 'max' => $max]);
            }
            if (abs($value / $step - round($value / $step)) > 1e-9) {
                throw new ApiException('mark_invalid', "Marks go in steps of {$step}.", 422, ['student_id' => $studentId]);
            }
        }

        if ($remark !== null && mb_strlen($remark) > (int) config('assessment.remark_max', 200)) {
            throw new ApiException('remark_too_long', 'A remark can be at most '.config('assessment.remark_max', 200).' characters.', 422, ['student_id' => $studentId]);
        }

        return ['student_id' => $studentId, 'value' => $value, 'code' => $code, 'remark' => $remark === '' ? null : $remark];
    }

    /**
     * @param  array{value?: float|null, code?: string|null, remark?: string|null}  $a
     * @param  array{value?: float|null, code?: string|null, remark?: string|null}  $b
     */
    private static function same(array $a, array $b): bool
    {
        return self::plain($a) === self::plain($b);
    }

    /**
     * @param  array{value?: mixed, code?: mixed, remark?: mixed}  $m
     * @return array{value: float|null, code: string|null, remark: string|null}
     */
    public static function plain(array $m): array
    {
        return [
            'value' => isset($m['value']) ? (float) $m['value'] : null,
            'code' => $m['code'] ?? null,
            'remark' => $m['remark'] ?? null,
        ];
    }

    /**
     * A mark is filled when it has a value or a code.
     *
     * @param  array<string, mixed>  $m
     */
    public static function filled(array $m): bool
    {
        return isset($m['value']) || isset($m['code']);
    }

    private function conflict(Paper $fresh): ApiException
    {
        return new ApiException('paper_conflict', 'Someone else saved marks on this paper since you opened it. Their marks are shown; check yours and save again.', 412, [
            'version' => $fresh->version,
            'etag' => $fresh->etag(),
        ]);
    }
}
