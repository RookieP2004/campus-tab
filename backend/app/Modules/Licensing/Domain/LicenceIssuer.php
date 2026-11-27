<?php

namespace App\Modules\Licensing\Domain;

use App\Modules\Licensing\Models\Licence;
use App\Modules\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\Validator;
use MongoDB\BSON\ObjectId;

/**
 * Platform side: issues, changes and revokes a school's licence.
 *
 *  - issue(): a new key, returned once to show to our team; only its hash is
 *    stored. For a school that is already active (its earlier licence was
 *    revoked) the new licence is active at once: the Chief already exists,
 *    so there is nothing to activate.
 *  - amend(): a new plan, seats, modules or end date. The payload is signed
 *    again under the same licence id; the key does not change.
 *  - revoke(): the school loses access at once (403 licence_inactive). Its
 *    data stays. A new licence can then be issued.
 */
class LicenceIssuer
{
    public function __construct(private readonly LicenceSigner $signer) {}

    /**
     * @param  array{plan: string, modules: list<string>, students: int, staff: int, valid_from: string, valid_to: string}  $terms
     * @return array{licence: Licence, key: string}
     */
    public function issue(Tenant $tenant, array $terms): array
    {
        $this->validate($terms);

        if (Licence::currentFor($tenant->id) !== null) {
            throw new DomainException("{$tenant->code} already has a licence. Revoke it first, or change its terms.");
        }

        $key = LicenceKey::generate();
        $id = new ObjectId;
        $active = $tenant->isActive();

        $licence = new Licence(array_merge($this->signedFields((string) $id, $tenant->id, $terms), [
            'tenant_id' => $tenant->id,
            'key_hash' => LicenceKey::hash($key),
            'key_last4' => LicenceKey::lastFour($key),
            'status' => $active ? Licence::STATUS_ACTIVE : Licence::STATUS_ISSUED,
            'activated_at' => $active ? now() : null,
        ]));
        $licence->setAttribute('id', $id);
        $licence->save();

        return ['licence' => $licence, 'key' => $key];
    }

    /**
     * @param  array{plan?: string, modules?: list<string>, students?: int, staff?: int, valid_from?: string, valid_to?: string}  $changes
     */
    public function amend(Licence $licence, array $changes): Licence
    {
        if ($licence->status === Licence::STATUS_REVOKED) {
            throw new DomainException('A revoked licence cannot be changed. Issue a new one.');
        }

        $terms = [
            'plan' => $changes['plan'] ?? $licence->plan,
            'modules' => $changes['modules'] ?? $licence->modules,
            'students' => (int) ($changes['students'] ?? $licence->seats['students']),
            'staff' => (int) ($changes['staff'] ?? $licence->seats['staff']),
            'valid_from' => $changes['valid_from'] ?? $licence->valid_from,
            'valid_to' => $changes['valid_to'] ?? $licence->valid_to,
        ];

        $this->validate($terms);

        $licence->forceFill($this->signedFields((string) $licence->getKey(), $licence->tenant_id, $terms))->save();

        return $licence;
    }

    public function revoke(Licence $licence): Licence
    {
        if ($licence->status === Licence::STATUS_REVOKED) {
            throw new DomainException('This licence is already revoked.');
        }

        $licence->forceFill(['status' => Licence::STATUS_REVOKED, 'revoked_at' => now()])->save();

        return $licence;
    }

    /**
     * What a licence record shows, for the console and the audit log. Never
     * the key hash or the signature.
     *
     * @return array<string, mixed>
     */
    public static function summary(Licence $licence): array
    {
        return [
            'id' => (string) $licence->getKey(),
            'key_last4' => $licence->key_last4,
            'plan' => $licence->plan,
            'modules' => $licence->modules,
            'seats' => $licence->seats,
            'valid_from' => $licence->valid_from,
            'valid_to' => $licence->valid_to,
            'status' => $licence->status,
            'state' => $licence->state()->value,
            'activated_at' => $licence->activated_at?->toIso8601String(),
            'revoked_at' => $licence->revoked_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array{plan: string, modules: list<string>, students: int, staff: int, valid_from: string, valid_to: string}  $terms
     * @return array<string, mixed>
     */
    private function signedFields(string $licenceId, string $tenantId, array $terms): array
    {
        $modules = array_values(array_unique($terms['modules']));
        $seats = ['students' => (int) $terms['students'], 'staff' => (int) $terms['staff']];

        $signed = $this->signer->sign([
            'licence_id' => $licenceId,
            'tenant_id' => $tenantId,
            'plan' => $terms['plan'],
            'modules' => $modules,
            'seats' => $seats,
            'valid_from' => $terms['valid_from'],
            'valid_to' => $terms['valid_to'],
            'issued_at' => CarbonImmutable::now()->toIso8601String(),
        ]);

        return [
            'plan' => $terms['plan'],
            'modules' => $modules,
            'seats' => $seats,
            'valid_from' => $terms['valid_from'],
            'valid_to' => $terms['valid_to'],
            'payload' => $signed['payload'],
            'signature' => $signed['signature'],
        ];
    }

    /**
     * @param  array<string, mixed>  $terms
     */
    private function validate(array $terms): void
    {
        Validator::make($terms, [
            'plan' => ['required', 'in:'.implode(',', config('licensing.plans'))],
            'modules' => ['present', 'array'],
            'modules.*' => ['in:'.implode(',', config('licensing.modules'))],
            'students' => ['required', 'integer', 'min:1', 'max:100000'],
            'staff' => ['required', 'integer', 'min:1', 'max:10000'],
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'valid_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
        ])->validate();
    }
}
