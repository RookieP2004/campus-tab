<?php

namespace App\Modules\Assessment\Policies;

use App\Modules\Assessment\Domain\PaperAccess;
use App\Modules\Assessment\Models\CorrectionRequest;
use App\Modules\Assessment\Models\Paper;
use App\Modules\Identity\Domain\AccessControl;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Tests and marks. A paper someone may not see does not exist for them
 * (404). Each lifecycle move is checked again in PaperLifecycle, and a
 * correction's decision in Corrections.
 */
class AssessmentPolicy
{
    public function __construct(
        private readonly PaperAccess $papers,
        private readonly AccessControl $access,
    ) {}

    public function view(User $user, Paper $paper): Response
    {
        return $this->papers->mayView($user, $paper) ? Response::allow() : Response::denyAsNotFound('The record was not found.');
    }

    /** A correction: its requester, the Principal, the Chief (school audit), or anyone who may raise one on that paper. */
    public function viewCorrection(User $user, CorrectionRequest $correction): Response
    {
        if ($correction->requested_by_id === (string) $user->getKey()
            || $this->access->allows($user, 'marks.correction_approve')
            || $this->access->allows($user, 'audit.view')) {
            return Response::allow();
        }

        $paper = Paper::query()->find($correction->paper_id);

        return $paper instanceof Paper && $this->papers->mayRequestCorrection($user, $paper) ? Response::allow() : Response::denyAsNotFound('The record was not found.');
    }

    public function manageScheme(User $user): Response
    {
        return $this->access->allows($user, 'assessment.scheme') || $this->access->allows($user, 'assessment.setup')
            ? Response::allow()
            : Response::deny('You do not have permission to do this.', 'permission_denied');
    }
}
