<?php

namespace App\Modules\Fees\Models;

use App\Support\Models\SchoolModel;

/**
 * One gateway payment we have acted on. The unique index on
 * (tenant_id, provider, gateway_payment_id) makes the webhook idempotent:
 * a replayed or repeated notification for the same payment changes nothing.
 *
 * @property string $provider
 * @property string $gateway_payment_id
 * @property string $order_id
 * @property string $event
 * @property string $outcome paid | failed | mismatch
 * @property array<string, mixed> $details
 */
class GatewayEvent extends SchoolModel
{
    protected $table = 'gateway_events';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'provider', 'gateway_payment_id', 'order_id', 'event', 'outcome', 'details'];

    /** @var array<string, mixed> */
    protected $attributes = ['details' => []];
}
