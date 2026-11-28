<?php

namespace App\Modules\Fees\Domain;

use App\Modules\Fees\Models\CashClosing;
use App\Modules\Fees\Models\Payment;
use App\Modules\Identity\Domain\AccessControl;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use App\Support\Http\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Day-end cash closing. Each collector closes their own day: every counter
 * payment they took that is not in a closing yet, totalled by mode.
 * Confirming the cash handed over (fees.closing_confirm) is someone else's
 * job (owner's rule 5):
 *
 *  - never the collector themself;
 *  - when the collector is a Clerk, only the Principal or the Chief.
 */
class Closings
{
    public function __construct(private readonly AccessControl $access) {}

    /**
     * @return Collection<int, Payment>
     */
    public function open(User $user): Collection
    {
        return Payment::query()->where('collector_id', (string) $user->getKey())->whereNull('closing_id')->where('mode', '!=', 'online')
            ->orderBy('created_at')->get();
    }

    public function close(User $user, ?string $note = null): CashClosing
    {
        if (! $this->access->allows($user, 'fees.collect')) {
            throw new ApiException('permission_denied', 'Only someone who collects fees closes a day.', 403);
        }

        return DB::connection('mongodb')->transaction(function () use ($user, $note): CashClosing {
            $payments = $this->open($user);
            if ($payments->isEmpty()) {
                throw new ApiException('nothing_to_close', 'You have no counter payments since your last closing.', 409);
            }
            $totals = [];
            foreach ($payments as $p) {
                // Cancelled ones still count: the money was taken (the reversal is in the ledger).
                $totals[$p->mode] = ($totals[$p->mode] ?? 0) + $p->amount_paise;
            }
            $closing = CashClosing::create([
                'collector' => ['id' => (string) $user->getKey(), 'name' => $user->name, 'roles' => array_values($user->roles ?? [])], 'collector_id' => (string) $user->getKey(),
                'date' => CarbonImmutable::now('Asia/Kolkata')->toDateString(),
                'payment_ids' => $payments->map(fn ($p) => (string) $p->getKey())->values()->all(),
                'totals' => $totals, 'total_paise' => array_sum($totals), 'cash_paise' => $totals['cash'] ?? 0, 'note' => $note,
            ]);
            Payment::query()->whereIn('_id', $closing->payment_ids)->whereNull('closing_id')->update(['closing_id' => (string) $closing->getKey()]);

            return $closing;
        }, 5);
    }

    public function confirm(User $user, CashClosing $closing, int $countedCash, ?string $note): CashClosing
    {
        if (! $this->mayConfirm($user, $closing)) {
            throw new ApiException(
                ($closing->collector['id'] ?? null) === (string) $user->getKey() ? 'own_closing' : 'permission_denied',
                ($closing->collector['id'] ?? null) === (string) $user->getKey()
                    ? 'Someone else confirms your day-end closing.'
                    : 'You may not confirm this closing. A Clerk\'s own closing is confirmed by the Principal.',
                403,
            );
        }
        if ($closing->status !== 'submitted') {
            throw new ApiException('closing_confirmed', 'This closing was already confirmed.', 409);
        }
        $closing->fill([
            'status' => 'confirmed', 'counted_cash_paise' => $countedCash, 'confirmed_by' => Cheques::who($user), 'confirmed_at' => now()->toIso8601String(),
            'note' => trim(implode(' · ', array_filter([$closing->note, $note]))) ?: null,
        ])->save();

        return $closing;
    }

    public function mayConfirm(User $user, CashClosing $closing): bool
    {
        if (($closing->collector['id'] ?? null) === (string) $user->getKey() || ! $this->access->allows($user, 'fees.closing_confirm')) {
            return false;
        }
        if (in_array(Role::Clerk->value, $closing->collector['roles'] ?? [], true)) {
            return (bool) array_intersect([Role::Principal->value, Role::Chief->value], $user->roles ?? []);
        }

        return true;
    }
}
