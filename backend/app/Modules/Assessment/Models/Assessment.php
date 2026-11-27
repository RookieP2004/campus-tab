<?php

namespace App\Modules\Assessment\Models;

use App\Support\Models\SchoolModel;

/**
 * An exam event (Unit Test 2, Half-yearly) or a class test. Its papers
 * (one per section and subject) carry the marks.
 *
 * kind: exam (scheduled by the Principal or the Clerk) or class_test (made
 * by a teacher for their own sections and subjects).
 *
 * @property string $year_id
 * @property string $kind exam | class_test
 * @property string $type weekly | monthly | periodic | half_yearly | annual | practical | project | internal
 * @property string|null $term
 * @property string $name
 * @property string|null $component scheme component key
 * @property string $from Y-m-d
 * @property string $to Y-m-d
 * @property string|null $entry_closes_at ISO 8601
 * @property array{id: string, name: string} $created_by
 */
class Assessment extends SchoolModel
{
    protected $table = 'assessments';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'year_id', 'kind', 'type', 'term', 'name', 'component', 'from', 'to', 'entry_closes_at', 'created_by'];

    /** @var array<string, mixed> */
    protected $attributes = ['kind' => 'exam', 'term' => null, 'component' => null, 'entry_closes_at' => null];

    public function isClassTest(): bool
    {
        return $this->kind === 'class_test';
    }
}
