<?php

namespace App\Modules\AcademicStructure\Models;

use App\Support\Models\SchoolModel;

/**
 * One section of a class in a year, e.g. 7-B. Its class teacher (a staff
 * id) gets the "class" scope over it; its wing gives a Vice Principal the
 * "wing" scope.
 *
 * @property string $year_id
 * @property bool|null $draft rolled forward by the year-end wizard, not yet confirmed
 * @property string $class_id
 * @property string $name e.g. "B"
 * @property string $label e.g. "7-B"
 * @property string|null $wing
 * @property string|null $class_teacher_id staff id
 * @property string|null $room
 */
class Section extends SchoolModel
{
    protected $table = 'sections';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'year_id', 'class_id', 'name', 'label', 'wing', 'class_teacher_id', 'room', 'draft'];

    /** @var array<string, mixed> */
    protected $attributes = ['wing' => null, 'class_teacher_id' => null, 'room' => null];
}
