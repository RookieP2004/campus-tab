<?php

namespace App\Modules\Fees\Models;

use App\Support\Models\SchoolModel;

/**
 * The school's own payment gateway account (money settles straight into
 * the school's bank). The Chief enters the keys in the app; the secret and
 * the webhook secret are stored encrypted (Laravel's encrypter, APP_KEY)
 * and never sent back to a screen or written to the audit log.
 *
 * @property string $provider razorpay | fake
 * @property string $key_id
 * @property string $key_secret
 * @property string $webhook_secret
 * @property string $status active | off
 * @property array{id: string, name: string}|null $updated_by
 */
class GatewayAccount extends SchoolModel
{
    protected $table = 'gateway_accounts';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'provider', 'key_id', 'key_secret', 'webhook_secret', 'status', 'updated_by'];

    /** @var list<string> */
    protected $hidden = ['key_secret', 'webhook_secret'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'active', 'updated_by' => null];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['key_secret' => 'encrypted', 'webhook_secret' => 'encrypted'];
    }

    /** @return list<string> */
    public function auditIgnored(): array
    {
        return ['key_secret', 'webhook_secret', 'updated_at'];
    }
}
