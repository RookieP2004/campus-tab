<?php

namespace App\Modules\AcademicStructure\Models;

use App\Support\Models\SchoolModel;

/**
 * Who teaches what in a year: one teacher (staff id), one subject, one
 * section. It gives the teacher the "subject" scope over that
 * subject-section ("<section_id>:<subject_id>").
 *
 * @property string $year_id
 * @property string $section_id
 * @property string $subject_id
 * @property string $teacher_id staff id
 */
class TeachingAssignment extends SchoolModel
{
    protected $table = 'teaching_assignments';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'year_id', 'section_id', 'subject_id', 'teacher_id'];

    public function subjectKey(): string
    {
        return $this->section_id.':'.$this->subject_id;
    }
}
