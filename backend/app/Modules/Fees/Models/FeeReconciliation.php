<?php

namespace App\Modules\Fees\Models;

use App\Support\Models\SchoolModel;

/**
 * One night's check of the gateway's settlement report against our online
 * payments. Mismatches are flagged to the Clerk.
 *
 *   mismatches[] {gateway_payment_id, order_id|null, reason: missing_here|missing_there|amount, ours_paise|null, theirs_paise|null}
 *
 * @property string $date Y-m-d the day checked
 * @property string $provider
 * @property int $matched
 * @property list<array<string, mixed>> $mismatches
 * @property string $status ok | mismatch | failed
 * @property string|null $error
 */
class FeeReconciliation extends SchoolModel
{
    protected $table = 'fee_reconciliations';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'date', 'provider', 'matched', 'mismatches', 'status', 'error'];

    /** @var array<string, mixed> */
    protected $attributes = ['matched' => 0, 'mismatches' => [], 'error' => null];
}
