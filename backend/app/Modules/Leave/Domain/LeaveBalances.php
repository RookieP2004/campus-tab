<?php

namespace App\Modules\Leave\Domain;

use App\Modules\Identity\Models\User;
use App\Modules\Leave\Models\LeaveBalanceLock;
use App\Modules\Leave\Models\LeaveLedgerEntry;
use App\Modules\Leave\Models\LeaveType;
use App\Support\Http\ApiException;
use Illuminate\Database\UniqueConstraintViolationException;
use MongoDB\Driver\Exception\BulkWriteException;

/**
 * Leave balances, worked out from the append-only ledger:
 *
 *   available = quota - reserved - taken
 *
 * Every write goes through lock() first, inside the caller's transaction:
 * it bumps the balance's lock document, so two transactions on the same
 * balance collide and the later one runs again with the new numbers.
 */
class LeaveBalances
{
    /**
     * @return array{type_code: string, type_name: string, quota: float|null, reserved: float, taken: float, available: float|null}
     */
    public function of(string $staffId, LeaveType $type, string $yearId): array
    {
        $reserved = 0.0;
        $taken = 0.0;

        $entries = LeaveLedgerEntry::query()->where('staff_id', $staffId)->where('year_id', $yearId)->where('type_code', $type->code)->get(['kind', 'days']);
        foreach ($entries as $entry) {
            match ($entry->kind) {
                'reserve' => $reserved += $entry->days,
                'release' => $reserved -= $entry->days,
                'deduct' => $taken += $entry->days,
                'restore' => $taken -= $entry->days,
                default => null,
            };
        }

        $quota = $type->quota === null ? null : (float) $type->quota;

        return [
            'type_code' => $type->code,
            'type_name' => $type->name,
            'quota' => $quota,
            'reserved' => round($reserved, 1),
            'taken' => round($taken, 1),
            'available' => $quota === null ? null : round($quota - $reserved - $taken, 1),
        ];
    }

    /**
     * Every active type's balance for one person.
     *
     * @return list<array{type_code: string, type_name: string, quota: float|null, reserved: float, taken: float, available: float|null}>
     */
    public function all(string $staffId, string $yearId): array
    {
        return LeaveType::query()->where('status', 'active')->orderBy('code')->get()
            ->map(fn (LeaveType $t) => $this->of($staffId, $t, $yearId))->values()->all();
    }

    /** Makes sure the balance's lock document exists (outside any transaction). */
    public function prepare(string $staffId, string $typeCode, string $yearId): void
    {
        $where = ['staff_id' => $staffId, 'year_id' => $yearId, 'type_code' => $typeCode];

        if (LeaveBalanceLock::query()->where($where)->exists()) {
            return;
        }

        try {
            LeaveBalanceLock::create($where);
        } catch (UniqueConstraintViolationException|BulkWriteException) {
            // Someone else made it at the same moment: that is all we needed.
        }
    }

    /** Inside a transaction: take the balance's write lock before reading it. */
    public function lock(string $staffId, string $typeCode, string $yearId): void
    {
        $lock = LeaveBalanceLock::query()->where('staff_id', $staffId)->where('year_id', $yearId)->where('type_code', $typeCode)->first();

        if (! $lock instanceof LeaveBalanceLock) {
            throw new \LogicException('Call prepare() before the transaction.');
        }

        $lock->forceFill(['version' => $lock->version + 1])->save();
    }

    /** Inside a transaction, after lock(): refuse if the days are not there. */
    public function assertAvailable(string $staffId, LeaveType $type, string $yearId, float $days): void
    {
        $balance = $this->of($staffId, $type, $yearId);

        if ($balance['available'] !== null && $days > $balance['available'] + 1e-9) {
            throw new ApiException('insufficient_balance', "Not enough {$type->name} left: {$balance['available']} available, {$days} asked for.", 422, [
                'available' => $balance['available'], 'asked' => $days,
            ]);
        }
    }

    public function write(string $kind, string $staffId, string $typeCode, string $yearId, float $days, string $requestId, ?User $by): void
    {
        if ($days <= 0) {
            return;
        }

        LeaveLedgerEntry::create([
            'staff_id' => $staffId, 'year_id' => $yearId, 'type_code' => $typeCode, 'kind' => $kind, 'days' => $days,
            'request_id' => $requestId, 'by' => $by === null ? null : ['id' => (string) $by->getKey(), 'name' => $by->name],
        ]);
    }
}
