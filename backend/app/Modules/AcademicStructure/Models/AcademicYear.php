<?php

namespace App\Modules\AcademicStructure\Models;

use App\Support\Models\SchoolModel;

/**
 * A school year, e.g. "2026-27". At most one is "current"; sections and
 * teaching assignments belong to a year, so a new year starts clean.
 *
 * @property string $name
 * @property string $starts_on Y-m-d
 * @property string $ends_on Y-m-d
 * @property string $status planned | current | closed
 */
class AcademicYear extends SchoolModel
{
    public const STATUS_PLANNED = 'planned';

    public const STATUS_CURRENT = 'current';

    public const STATUS_CLOSED = 'closed';

    protected $table = 'academic_years';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'name', 'starts_on', 'ends_on', 'status'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::STATUS_PLANNED];

    public static function current(): ?self
    {
        return self::query()->where('status', self::STATUS_CURRENT)->first();
    }
}
