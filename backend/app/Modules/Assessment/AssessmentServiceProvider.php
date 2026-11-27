<?php

namespace App\Modules\Assessment;

use App\Modules\Assessment\Models\Assessment;
use App\Modules\Assessment\Models\AssessmentScheme;
use App\Modules\Assessment\Models\CorrectionRequest;
use App\Modules\Assessment\Models\Paper;
use App\Modules\Assessment\Policies\AssessmentPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Assessment: schemes, exams and class tests, papers and the markbook, the
 * marks lifecycle and corrections after lock.
 */
class AssessmentServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        foreach ([Paper::class, Assessment::class, AssessmentScheme::class, CorrectionRequest::class] as $model) {
            Gate::policy($model, AssessmentPolicy::class);
        }
    }
}
