<?php

namespace App\Support\Models;

use App\Modules\AcademicStructure\Models\AcademicYear;
use App\Support\Http\ApiException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;

/**
 * After the year-end promotion closes a year, that year is read-only: no
 * school record that belongs to it (it carries the year's year_id: classes,
 * sections, assignments, enrolments, attendance, exams, papers, report
 * cards, leave) can be created, changed or deleted. SchoolModel calls this
 * on every save and delete; code that writes through the query builder
 * (the markbook's conditional save) calls assertOpen() itself.
 *
 * One lookup per year per request or job (scoped), cleared when a year
 * changes.
 */
class ClosedYears
{
    /** @var array<string, bool> tenant:year => closed */
    private array $closed = [];

    public function __construct(private readonly TenantContext $tenant) {}

    public static function guard(Model $model): void
    {
        if ($model instanceof AcademicYear) {
            app(self::class)->forget();

            return;
        }
        foreach (array_unique(array_filter([$model->getAttribute('year_id'), $model->getOriginal('year_id')])) as $yearId) {
            app(self::class)->assertOpen((string) $yearId);
        }
    }

    public function assertOpen(?string $yearId): void
    {
        if ($yearId === null || $yearId === '' || $this->tenant->id() === null) {
            return;
        }
        $key = $this->tenant->id().':'.$yearId;
        $this->closed[$key] ??= AcademicYear::query()->where('_id', $yearId)->where('status', AcademicYear::STATUS_CLOSED)->exists();

        if ($this->closed[$key]) {
            throw new ApiException('year_closed', 'That academic year is closed and can only be viewed now.', 409, ['year_id' => $yearId]);
        }
    }

    public function forget(): void
    {
        $this->closed = [];
    }
}
