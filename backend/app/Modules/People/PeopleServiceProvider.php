<?php

namespace App\Modules\People;

use App\Modules\AcademicStructure\Models\AcademicYear;
use App\Modules\AcademicStructure\Models\SchoolClass;
use App\Modules\AcademicStructure\Models\Section;
use App\Modules\AcademicStructure\Models\Subject;
use App\Modules\AcademicStructure\Models\TeachingAssignment;
use App\Modules\AcademicStructure\Policies\AcademicStructurePolicy;
use App\Modules\Customisation\Models\CustomField;
use App\Modules\Customisation\Policies\CustomFieldPolicy;
use App\Modules\Identity\Domain\Scopes\AccessScopeSource;
use App\Modules\People\Domain\RecordAccessScopes;
use App\Modules\People\Models\Guardian;
use App\Modules\People\Models\ImportBatch;
use App\Modules\People\Models\Staff;
use App\Modules\People\Models\Student;
use App\Modules\People\Policies\PeoplePolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * People, academic structure and customisation. Registers their policies, binds the real scope facts (guardians,
 * class teachers, teaching assignments, wings) and forgets them whenever one
 * of those records changes.
 */
class PeopleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(AccessScopeSource::class, RecordAccessScopes::class);
    }

    public function boot(): void
    {
        foreach ([AcademicYear::class, SchoolClass::class, Section::class, Subject::class, TeachingAssignment::class] as $model) {
            Gate::policy($model, AcademicStructurePolicy::class);
        }

        foreach ([Student::class, Guardian::class, Staff::class, ImportBatch::class] as $model) {
            Gate::policy($model, PeoplePolicy::class);
        }

        Gate::policy(CustomField::class, CustomFieldPolicy::class);

        $flush = fn () => app(AccessScopeSource::class)->flush();

        foreach ([Guardian::class, Staff::class, Section::class, TeachingAssignment::class] as $model) {
            $model::saved($flush);
            $model::deleted($flush);
        }
    }
}
