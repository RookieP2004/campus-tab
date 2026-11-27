<?php

namespace App\Modules\Assessment\Models;

use App\Support\Models\SchoolModel;

/**
 * The school's own assessment scheme for a group of classes in a year:
 * terms, components with maximum marks and weightage, the grade scale and
 * rules (pass percentage, rounding, best-of, grace). It may start from a
 * starter preset (config/assessment.php), which is only a starting point:
 * the Principal changes everything.
 *
 * @property string $year_id
 * @property string $name
 * @property list<string> $grades class grades it covers, e.g. ["6", "7", "8"]
 * @property list<string> $terms
 * @property list<array{key: string, name: string, max: float, weight: float}> $components
 * @property list<array{grade: string, min: float}> $grade_scale
 * @property array{pass_percent: float, rounding: string, best_of: int|null, grace_marks: float} $rules
 * @property string|null $preset
 * @property string $status draft | active
 */
class AssessmentScheme extends SchoolModel
{
    protected $table = 'assessment_schemes';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'year_id', 'name', 'grades', 'terms', 'components', 'grade_scale', 'rules', 'preset', 'status'];

    /** @var array<string, mixed> */
    protected $attributes = ['grades' => [], 'terms' => [], 'components' => [], 'grade_scale' => [], 'preset' => null, 'status' => 'draft'];
}
