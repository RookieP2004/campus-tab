<?php

namespace App\Modules\Fees\Domain;

use App\Modules\Fees\Models\FeeCounter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use LogicException;
use MongoDB\Collection;
use MongoDB\Laravel\Connection;
use MongoDB\Operation\FindOneAndUpdate;

/**
 * Receipt numbers: one counter per school per year, incremented with a
 * single atomic findOneAndUpdate INSIDE the payment's transaction.
 *
 *  - Two payments at once both touch the same counter document, so MongoDB
 *    lets only one transaction commit; the other gets a write conflict and
 *    is retried with the next number. Numbers are in sequence.
 *  - If the payment fails, the increment is rolled back with it, so no
 *    number is lost and none is ever handed out twice.
 *  - A cancelled receipt keeps its number (it is never deleted).
 */
class FeeCounters
{
    public function __construct(private readonly TenantContext $tenant) {}

    /** The next number for $key. Must run inside a MongoDB transaction. */
    public function next(string $key): int
    {
        /** @var Connection $db */
        $db = DB::connection('mongodb');
        $session = $db->getSession();
        if ($session === null || ! $session->isInTransaction()) {
            throw new LogicException('Receipt numbers are taken only inside the payment transaction.');
        }
        $tenantId = $this->tenant->require();

        // The builder turns the returned document into a model.
        $doc = FeeCounter::raw(fn (Collection $c) => $c->findOneAndUpdate(
            ['tenant_id' => $tenantId, 'key' => $key],
            ['$inc' => ['value' => 1], '$setOnInsert' => ['tenant_id' => $tenantId, 'key' => $key]],
            ['upsert' => true, 'returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER, 'session' => $session],
        ));
        $value = $doc instanceof FeeCounter ? $doc->value : (is_array($doc) || is_object($doc) ? ((array) $doc)['value'] ?? null : null);
        if (! is_int($value) || $value < 1) {
            throw new LogicException('The receipt counter did not return a number.');
        }

        return $value;
    }

    /**
     * "SPS/2026-27/000042": the school's prefix (policies.fees.receipt_prefix),
     * the year and the number. The year is always in it exactly once (a
     * prefix may already hold it, e.g. "SPS/2026-27/"), so numbers never
     * repeat from one year to the next.
     */
    public static function receiptNumber(?string $prefix, string $yearName, int $seq): string
    {
        $base = trim((string) $prefix);
        $number = str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
        if ($base === '') {
            return $yearName.'/'.$number;
        }
        $sep = fn (string $s) => str_ends_with($s, '/') || str_ends_with($s, '-') ? '' : '/';
        if (! str_contains($base, $yearName)) {
            $base .= $sep($base).$yearName;
        }

        return $base.$sep($base).$number;
    }
}
