<?php

namespace App\Modules\People\Models;

use App\Support\Models\SchoolModel;

/**
 * A student in a year: section and roll number. Section- and
 * subject-section-scoped custom values live here, so they reset with the
 * new year.
 *
 * @property string $student_id
 * @property string $year_id
 * @property string $section_id
 * @property int|null $roll_no
 * @property string|null $result
 * @property array<string, mixed> $custom
 */
class Enrolment extends SchoolModel
{
    protected $table = 'enrolments';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'student_id', 'year_id', 'section_id', 'roll_no', 'result', 'custom'];

    /** @var array<string, mixed> */
    protected $attributes = ['custom' => [], 'result' => null, 'roll_no' => null];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['roll_no' => 'integer'];
    }
}
