<?php

namespace App\Modules\Fees\Domain;

use App\Modules\Approvals\Domain\ApprovalSource;
use App\Modules\Fees\Models\CashClosing;
use App\Modules\Fees\Models\FeeAdjustment;
use App\Modules\Fees\Models\ReceiptCancellation;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Domain\SchoolConfigService;

/**
 * Fee items waiting on someone, in the shared approvals inbox: proposed
 * adjustments and receipt cancellations (for whoever may approve them,
 * never their own) and day-end closings (for whoever may confirm them).
 * A collector's cancellation request also shows to the Clerk to forward.
 */
class FeeApprovals implements ApprovalSource
{
    public function __construct(
        private readonly Adjustments $adjustments,
        private readonly Cancellations $cancellations,
        private readonly Closings $closings,
        private readonly SchoolConfigService $configs,
    ) {}

    public function kind(): string
    {
        return 'fees';
    }

    public function itemsFor(User $user): array
    {
        if (! (bool) ($this->configs->current()->body['modules']['fees'] ?? false)) {
            return [];
        }
        $me = (string) $user->getKey();
        $items = [];

        if ($this->adjustments->mayApprove($user)) {
            foreach (FeeAdjustment::query()->where('status', 'proposed')->orderBy('created_at')->limit(200)->get() as $a) {
                if (($a->proposed_by['id'] ?? null) === $me) {
                    continue;
                }
                $items[] = ['kind' => 'fees', 'id' => 'adjustment:'.$a->getKey(), 'title' => $a->student_name, 'detail' => null,
                    'subtitle' => ['type' => 'adjustment', 'adjustment_kind' => $a->kind, 'amount_paise' => $a->amount_paise, 'by' => $a->proposed_by['name'] ?? null],
                    'status' => 'pending', 'at' => $a->created_at?->toIso8601String(), 'link' => '/approvals?item=fees:adjustment:'.$a->getKey(), 'flags' => []];
            }
        }

        $approver = $this->cancellations->mayApprove($user);
        $proposer = $this->cancellations->mayPropose($user);
        if ($approver || $proposer) {
            foreach (ReceiptCancellation::query()->whereIn('status', ['requested', 'proposed'])->orderBy('created_at')->limit(200)->get() as $c) {
                $mine = $c->involves($me);
                if ($mine || (! $approver && $c->status !== 'requested')) {
                    continue;
                }
                $items[] = ['kind' => 'fees', 'id' => 'cancellation:'.$c->getKey(), 'title' => $c->student_name.' · '.$c->receipt_no, 'detail' => null,
                    'subtitle' => ['type' => 'cancellation', 'stage' => $c->status, 'amount_paise' => $c->amount_paise, 'by' => $c->requested_by['name'] ?? null],
                    'status' => 'pending', 'at' => $c->created_at?->toIso8601String(), 'link' => '/approvals?item=fees:cancellation:'.$c->getKey(), 'flags' => []];
            }
        }

        foreach (CashClosing::query()->where('status', 'submitted')->orderBy('created_at')->limit(200)->get() as $closing) {
            if (! $this->closings->mayConfirm($user, $closing)) {
                continue;
            }
            $items[] = ['kind' => 'fees', 'id' => 'closing:'.$closing->getKey(), 'title' => $closing->collector['name'] ?? '', 'detail' => null,
                'subtitle' => ['type' => 'closing', 'date' => $closing->date, 'amount_paise' => $closing->total_paise, 'cash_paise' => $closing->cash_paise],
                'status' => 'pending', 'at' => $closing->created_at?->toIso8601String(), 'link' => '/approvals?item=fees:closing:'.$closing->getKey(), 'flags' => []];
        }

        return $items;
    }
}
