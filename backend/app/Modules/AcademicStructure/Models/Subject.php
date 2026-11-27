<?php

namespace App\Modules\AcademicStructure\Models;

use App\Support\Models\SchoolModel;

/**
 * A subject the school teaches, e.g. MATH "Mathematics".
 *
 * @property string $code
 * @property string $name
 * @property string $kind scholastic | co_scholastic
 * @property bool $elective
 */
class Subject extends SchoolModel
{
    protected $table = 'subjects';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'code', 'name', 'kind', 'elective'];

    /** @var array<string, mixed> */
    protected $attributes = ['kind' => 'scholastic', 'elective' => false];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['elective' => 'boolean'];
    }
}
