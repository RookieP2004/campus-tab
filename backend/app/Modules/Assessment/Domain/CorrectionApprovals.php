<?php

namespace App\Modules\Assessment\Domain;

use App\Modules\Approvals\Domain\ApprovalSource;
use App\Modules\Assessment\Models\CorrectionRequest;
use App\Modules\Identity\Domain\AccessControl;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Domain\SchoolConfigService;

/**
 * Pending corrections to locked marks, in the Principal's approvals inbox.
 * One the Principal raised themself is flagged: approving it is a separate
 * step with MFA, and it will show to the Chief as self-approved.
 */
class CorrectionApprovals implements ApprovalSource
{
    public function __construct(
        private readonly AccessControl $access,
        private readonly SchoolConfigService $configs,
    ) {}

    public function kind(): string
    {
        return 'marks';
    }

    public function itemsFor(User $user): array
    {
        if (! (bool) ($this->configs->current()->body['modules']['assessment'] ?? false) || ! $this->access->allows($user, 'marks.correction_approve')) {
            return [];
        }

        return CorrectionRequest::query()->where('status', 'pending')->orderBy('created_at')->limit(200)->get()
            ->map(fn (CorrectionRequest $c) => [
                'kind' => 'marks',
                'id' => (string) $c->getKey(),
                'title' => $c->label.' · '.$c->student_name,
                'detail' => null,
                'subtitle' => ['old' => $c->old, 'new' => $c->new, 'by' => $c->requested_by['name'] ?? null],
                'status' => 'pending',
                'at' => $c->created_at?->toIso8601String(),
                'link' => '/approvals?item=marks:'.$c->getKey(),
                'flags' => $c->requested_by_id === (string) $user->getKey() ? ['raised_by_you'] : [],
            ])->values()->all();
    }
}
