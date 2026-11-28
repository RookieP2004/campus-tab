<?php

namespace App\Modules\Fees\Models;

use App\Support\Models\SchoolModel;

/**
 * An online payment started by a family. The server made it: the amount
 * is the sum of the chosen dues, never a number from the browser. The
 * gateway's webhook is matched to it by order id (decision 0015).
 *
 * @property string $provider
 * @property string $order_id the gateway's order id
 * @property string $student_id
 * @property string $year_id
 * @property list<string> $demand_ids
 * @property int $amount_paise
 * @property string $currency
 * @property string $status created | paid | failed | mismatch
 * @property string|null $gateway_payment_id
 * @property string|null $payment_id
 * @property string|null $receipt_no
 * @property string $created_by user id
 */
class GatewayOrder extends SchoolModel
{
    protected $table = 'gateway_orders';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'provider', 'order_id', 'student_id', 'year_id', 'demand_ids', 'amount_paise', 'currency', 'status', 'gateway_payment_id', 'payment_id', 'receipt_no', 'created_by'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'created', 'gateway_payment_id' => null, 'payment_id' => null, 'receipt_no' => null];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount_paise' => 'integer'];
    }
}
