<?php

namespace App\Modules\AcademicStructure\Models;

use App\Support\Models\SchoolModel;

/**
 * A grade in a year (collection `classes`), e.g. "Class 7". Its sections
 * hold the students. The wing groups classes for a Vice Principal.
 *
 * @property string $year_id
 * @property string $grade e.g. "7", "LKG"
 * @property string $name e.g. "Class 7"
 * @property int $order
 * @property string|null $wing e.g. "primary", "middle"
 */
class SchoolClass extends SchoolModel
{
    protected $table = 'classes';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'year_id', 'grade', 'name', 'order', 'wing'];

    /** @var array<string, mixed> */
    protected $attributes = ['order' => 0, 'wing' => null];
}
