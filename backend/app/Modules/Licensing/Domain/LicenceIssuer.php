<?php

namespace App\Modules\Licensing\Domain;

use App\Modules\Licensing\Models\Licence;
use App\Modules\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\Validator;
use MongoDB\BSON\ObjectId;

/**
 * Platform side: issues a licence key for a school. The plain key is
 * returned once, to show to our team; only its hash is stored.
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
        Validator::make($terms, [
            'plan' => ['required', 'in:'.implode(',', config('licensing.plans'))],
            'modules' => ['present', 'array'],
            'modules.*' => ['in:'.implode(',', config('licensing.modules'))],
            'students' => ['required', 'integer', 'min:1'],
            'staff' => ['required', 'integer', 'min:1'],
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'valid_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
        ])->validate();

        if (Licence::currentFor($tenant->id) !== null) {
            throw new DomainException("{$tenant->code} already has a licence. Revoke it first, or renew it to extend the term.");
        }

        $key = LicenceKey::generate();
        $id = new ObjectId;
        $modules = array_values(array_unique($terms['modules']));
        $seats = ['students' => (int) $terms['students'], 'staff' => (int) $terms['staff']];

        $signed = $this->signer->sign([
            'licence_id' => (string) $id,
            'tenant_id' => $tenant->id,
            'plan' => $terms['plan'],
            'modules' => $modules,
            'seats' => $seats,
            'valid_from' => $terms['valid_from'],
            'valid_to' => $terms['valid_to'],
            'issued_at' => CarbonImmutable::now()->toIso8601String(),
        ]);

        $licence = new Licence([
            'tenant_id' => $tenant->id,
            'key_hash' => LicenceKey::hash($key),
            'key_last4' => LicenceKey::lastFour($key),
            'plan' => $terms['plan'],
            'modules' => $modules,
            'seats' => $seats,
            'valid_from' => $terms['valid_from'],
            'valid_to' => $terms['valid_to'],
            'status' => Licence::STATUS_ISSUED,
            'payload' => $signed['payload'],
            'signature' => $signed['signature'],
        ]);
        $licence->setAttribute('id', $id);
        $licence->save();

        return ['licence' => $licence, 'key' => $key];
    }
}
