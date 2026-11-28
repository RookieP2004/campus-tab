<?php

namespace App\Modules\Reports\Models;

use App\Support\Models\SchoolModel;

/**
 * The school's report card layout for a group of classes: blocks in order
 * (each with its options), an optional letterhead image and the preset it
 * started from (a starting point only). Logo and name come from the school
 * config at render time.
 *
 *   blocks[]    {type: header|student|scholastic|co_scholastic|attendance|descriptive|remarks|result|signatures, options: {...}}
 *   letterhead  {path, mime} under tenants/<tenant>/report-templates/, or null
 *
 * @property string $name
 * @property list<string> $grades
 * @property list<array{type: string, options: array<string, mixed>}> $blocks
 * @property array{path: string, mime: string}|null $letterhead
 * @property string|null $preset
 */
class ReportTemplate extends SchoolModel
{
    protected $table = 'report_templates';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'name', 'grades', 'blocks', 'letterhead', 'preset'];

    /** @var array<string, mixed> */
    protected $attributes = ['grades' => [], 'blocks' => [], 'letterhead' => null, 'preset' => null];
}
