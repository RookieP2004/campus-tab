<?php

namespace App\Modules\Licensing\Models;

use App\Modules\Licensing\Domain\LicenceSigner;
use App\Modules\Licensing\Domain\LicenceState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use MongoDB\Laravel\Eloquent\Model;

/**
 * A licence (platform level). The plain key is never stored: only key_hash
 * and the last four characters for display. The signed payload is the
 * source of truth; the plain fields copy it for queries and must match it.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $key_hash
 * @property string $key_last4
 * @property string $plan
 * @property list<string> $modules
 * @property array{students: int, staff: int} $seats
 * @property string $valid_from Y-m-d
 * @property string $valid_to Y-m-d (last valid day)
 * @property string $status issued | active | revoked
 * @property string $payload canonical JSON
 * @property string $signature base64 Ed25519 signature
 * @property Carbon|null $activated_at
 * @property Carbon|null $revoked_at
 */
class Licence extends Model
{
    public const STATUS_ISSUED = 'issued';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_REVOKED = 'revoked';

    protected $connection = 'mongodb';

    protected $table = 'licences';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'key_hash', 'key_last4', 'plan', 'modules', 'seats', 'valid_from', 'valid_to', 'status', 'payload', 'signature', 'activated_at', 'revoked_at'];

    /** @var list<string> */
    protected $hidden = ['key_hash', 'signature'];

    /** @var array<string, array<string, mixed>|null> */
    private array $verified = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['activated_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    /** The school's current licence: the newest one that is not revoked. */
    public static function currentFor(string $tenantId): ?self
    {
        return self::query()
            ->where('tenant_id', $tenantId)
            ->where('status', '!=', self::STATUS_REVOKED)
            ->orderBy('created_at', 'desc')
            ->first();
    }

    /**
     * The decoded payload when the signature is valid and the record's plain
     * fields still match it; otherwise null.
     *
     * @return array{licence_id?: string, tenant_id: string, plan: string, modules: list<string>, seats: array{students: int, staff: int}, valid_from: string, valid_to: string, issued_at?: string}|null
     */
    public function verifiedPayload(): ?array
    {
        $cacheKey = $this->payload.'|'.$this->signature;

        if (! array_key_exists($cacheKey, $this->verified)) {
            $this->verified = [$cacheKey => $this->checkPayload()];
        }

        /** @var array{tenant_id: string, plan: string, modules: list<string>, seats: array{students: int, staff: int}, valid_from: string, valid_to: string}|null */
        return $this->verified[$cacheKey];
    }

    public function state(?CarbonImmutable $now = null): LicenceState
    {
        $payload = $this->verifiedPayload();

        if ($payload === null || $this->status !== self::STATUS_ACTIVE) {
            return LicenceState::Inactive;
        }

        $tz = 'Asia/Kolkata';
        $now ??= CarbonImmutable::now($tz);
        $from = CarbonImmutable::parse($payload['valid_from'], $tz)->startOfDay();
        $to = CarbonImmutable::parse($payload['valid_to'], $tz)->endOfDay();
        $graceEnds = $to->addDays((int) config('licensing.grace_days', 15));

        return match (true) {
            $now->lessThan($from) => LicenceState::Inactive,
            $now->lessThanOrEqualTo($to) => LicenceState::Active,
            $now->lessThanOrEqualTo($graceEnds) => LicenceState::Grace,
            default => LicenceState::ReadOnly,
        };
    }

    /**
     * @return list<string>
     */
    public function licensedModules(): array
    {
        return $this->verifiedPayload()['modules'] ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function checkPayload(): ?array
    {
        if (! is_string($this->payload) || ! is_string($this->signature)) {
            return null;
        }

        if (! app(LicenceSigner::class)->verify($this->payload, $this->signature)) {
            return null;
        }

        /** @var array<string, mixed>|null $payload */
        $payload = json_decode($this->payload, true);

        if (! is_array($payload)) {
            return null;
        }

        $matches = ($payload['licence_id'] ?? null) === (string) $this->getKey()
            && ($payload['tenant_id'] ?? null) === $this->tenant_id
            && ($payload['plan'] ?? null) === $this->plan
            && ($payload['modules'] ?? null) === $this->modules
            && ($payload['valid_from'] ?? null) === $this->valid_from
            && ($payload['valid_to'] ?? null) === $this->valid_to
            && ($payload['seats'] ?? null) == $this->seats;

        return $matches ? $payload : null;
    }
}
