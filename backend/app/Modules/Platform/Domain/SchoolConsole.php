<?php

namespace App\Modules\Platform\Domain;

use App\Modules\Audit\Domain\PlatformAuditRecorder;
use App\Modules\Audit\Models\PlatformAuditLog;
use App\Modules\Identity\Models\PlatformAdmin;
use App\Modules\Licensing\Domain\LicenceIssuer;
use App\Modules\Licensing\Models\Licence;
use App\Modules\Platform\Models\PlatformAlert;
use App\Modules\Platform\Models\SchoolClient;
use App\Modules\Platform\Models\SchoolPayment;
use App\Modules\Platform\Models\SchoolStats;
use App\Modules\Tenancy\Domain\TenantProvisioner;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Http\ApiException;
use DomainException;

/**
 * What the console (and the platform:* commands) do to schools. Every
 * action goes in the platform audit log; every action on a school also goes
 * in that school's own audit log, so the school sees it.
 *
 * The actor is the signed-in platform admin, or null for a command run on
 * the server ("command line").
 */
class SchoolConsole
{
    public function __construct(
        private readonly TenantProvisioner $provisioner,
        private readonly LicenceIssuer $issuer,
        private readonly PlatformAuditRecorder $audit,
    ) {}

    /**
     * @param  array{code: string, name: string, email?: string|null, phone?: string|null}  $input
     */
    public function createSchool(?PlatformAdmin $actor, array $input): Tenant
    {
        $tenant = $this->provisioner->create($input);

        $this->audit->record('school.created', $actor, $tenant, 'tenants', $tenant->id, after: [
            'code' => $tenant->code,
            'name' => $tenant->name,
            'contact' => $tenant->contact,
        ], schoolCategory: 'admin');

        return $tenant;
    }

    /**
     * @param  array{plan: string, modules: list<string>, students: int, staff: int, valid_from: string, valid_to: string}  $terms
     * @return array{licence: Licence, key: string}
     */
    public function issueLicence(?PlatformAdmin $actor, Tenant $tenant, array $terms): array
    {
        try {
            $result = $this->issuer->issue($tenant, $terms);
        } catch (DomainException $e) {
            throw new ApiException('licence_exists', $e->getMessage(), 409);
        }

        $this->audit->record('licence.issued', $actor, $tenant, 'licences', (string) $result['licence']->getKey(),
            after: LicenceIssuer::summary($result['licence']), schoolCategory: 'security');

        return $result;
    }

    /**
     * @param  array{plan?: string, modules?: list<string>, students?: int, staff?: int, valid_from?: string, valid_to?: string}  $changes
     */
    public function amendLicence(?PlatformAdmin $actor, Tenant $tenant, array $changes): Licence
    {
        $licence = $this->currentLicence($tenant);
        $before = LicenceIssuer::summary($licence);

        try {
            $licence = $this->issuer->amend($licence, $changes);
        } catch (DomainException $e) {
            throw new ApiException('licence_not_changeable', $e->getMessage(), 409);
        }

        $after = LicenceIssuer::summary($licence);
        $changed = array_values(array_filter(['plan', 'modules', 'seats', 'valid_from', 'valid_to'], fn (string $f) => $before[$f] != $after[$f]));

        $this->audit->record('licence.changed', $actor, $tenant, 'licences', (string) $licence->getKey(),
            before: $before, after: $after, meta: ['changed' => $changed], schoolCategory: 'security');

        return $licence;
    }

    /**
     * Needs the school code typed again: a revoke shuts the school out at once.
     */
    public function revokeLicence(?PlatformAdmin $actor, Tenant $tenant, string $typedCode, string $reason): Licence
    {
        if (Tenant::normaliseCode($typedCode) !== $tenant->code) {
            throw new ApiException('confirmation_mismatch', 'Type the school code exactly to confirm.', 422, ['expected' => 'school_code']);
        }

        $licence = $this->currentLicence($tenant);
        $before = LicenceIssuer::summary($licence);
        $licence = $this->issuer->revoke($licence);

        $this->audit->record('licence.revoked', $actor, $tenant, 'licences', (string) $licence->getKey(),
            before: $before, after: LicenceIssuer::summary($licence), meta: ['reason' => $reason], schoolCategory: 'security');

        return $licence;
    }

    public function currentLicence(Tenant $tenant): Licence
    {
        return Licence::currentFor($tenant->id)
            ?? throw new ApiException('licence_not_found', 'This school has no licence that can be changed.', 404);
    }

    /**
     * A renewal: a later last valid day. Takes effect at once (every request
     * reads the current licence) and is recorded as licence.renewed in both
     * audit logs.
     */
    public function renewLicence(?PlatformAdmin $actor, Tenant $tenant, string $validTo): Licence
    {
        $licence = $this->currentLicence($tenant);

        if ($validTo <= $licence->valid_to) {
            throw new ApiException('renewal_not_later', 'A renewal must end later than '.$licence->valid_to.'.', 422);
        }

        $before = LicenceIssuer::summary($licence);

        try {
            $licence = $this->issuer->amend($licence, ['valid_to' => $validTo]);
        } catch (DomainException $e) {
            throw new ApiException('licence_not_changeable', $e->getMessage(), 409);
        }

        $this->audit->record('licence.renewed', $actor, $tenant, 'licences', (string) $licence->getKey(),
            before: $before, after: LicenceIssuer::summary($licence), meta: ['changed' => ['valid_to']], schoolCategory: 'security');

        return $licence;
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function updateClient(PlatformAdmin $actor, Tenant $tenant, array $changes): SchoolClient
    {
        $client = SchoolClient::query()->where('school_id', $tenant->id)->first() ?? new SchoolClient(['school_id' => $tenant->id]);
        $before = $client->exists ? $client->present() : null;
        $client->fill(array_intersect_key($changes, array_flip(SchoolClient::FIELDS)))->save();

        // Our billing record: the platform audit log only.
        $this->audit->record('client.updated', $actor, $tenant, 'school_clients', (string) $client->getKey(), before: $before, after: $client->present());

        return $client;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function recordPayment(PlatformAdmin $actor, Tenant $tenant, array $input): SchoolPayment
    {
        $payment = SchoolPayment::create(array_merge(
            array_intersect_key($input, array_flip(['amount', 'paid_on', 'method', 'reference', 'period_from', 'period_to', 'note'])),
            ['school_id' => $tenant->id, 'recorded_by' => ['id' => (string) $actor->getKey(), 'name' => $actor->name]],
        ));

        $this->audit->record('payment.recorded', $actor, $tenant, 'school_payments', (string) $payment->getKey(), after: $payment->present());

        return $payment;
    }

    /**
     * One row of the school list. Platform data only: the licence, our client
     * record, and the numbers the scheduled job stored. Nothing here reads the
     * school's own collections (that needs a support grant).
     *
     * @return array<string, mixed>
     */
    public function present(Tenant $tenant): array
    {
        $licence = Licence::currentFor($tenant->id);
        $stats = SchoolStats::query()->where('school_id', $tenant->id)->first();
        $client = SchoolClient::query()->where('school_id', $tenant->id)->first();
        $usage = $stats?->usage;

        return [
            'id' => $tenant->id,
            'code' => $tenant->code,
            'name' => $tenant->name,
            'status' => $tenant->status,
            'contact' => [
                'email' => $tenant->contact['email'] ?? null,
                'phone' => $tenant->contact['phone'] ?? null,
            ],
            'licence' => $licence === null ? null : LicenceIssuer::summary($licence),
            'usage' => [
                'students' => $usage['students'] ?? null,
                'student_seats' => $licence?->seats['students'] ?? null,
                'staff' => $usage['staff'] ?? null,
                'staff_seats' => $licence?->seats['staff'] ?? null,
                'active_7d' => $usage['active_7d'] ?? null,
                'active_30d' => $usage['active_30d'] ?? null,
                'last_activity_at' => $usage['last_activity_at'] ?? null,
                'storage_bytes' => $usage['storage_bytes'] ?? null,
                'computed_at' => $stats?->computed_at?->toIso8601String(),
            ],
            'client' => $client === null ? null : ['package_name' => $client->package_name, 'paid_until' => $client->paid_until, 'billing_period' => $client->billing_period],
            'open_alerts' => PlatformAlert::query()->where('school_id', $tenant->id)->whereNull('acknowledged_at')->count(),
            'created_at' => $tenant->created_at?->toIso8601String(),
        ];
    }

    /**
     * The school page: the list row plus the client record and payments,
     * licence history, support grants and the security overview (counts).
     *
     * @return array<string, mixed>
     */
    public function details(Tenant $tenant): array
    {
        $stats = SchoolStats::query()->where('school_id', $tenant->id)->first();
        $client = SchoolClient::query()->where('school_id', $tenant->id)->first();

        $history = PlatformAuditLog::query()->where('school_id', $tenant->id)
            ->whereIn('action', ['licence.issued', 'licence.changed', 'licence.renewed', 'licence.revoked'])
            ->orderBy('at', 'desc')->limit(50)->get()
            ->map(fn (PlatformAuditLog $e) => [
                'action' => substr($e->action, strlen('licence.')),
                'at' => $e->at->toIso8601String(),
                'by' => $e->actor['name'] ?? null,
                'changed' => $e->meta['changed'] ?? null,
                'reason' => $e->meta['reason'] ?? null,
                'before' => $e->before === null ? null : array_intersect_key($e->before, array_flip(['plan', 'seats', 'modules', 'valid_to', 'status'])),
                'after' => $e->after === null ? null : array_intersect_key($e->after, array_flip(['plan', 'seats', 'modules', 'valid_from', 'valid_to', 'status'])),
            ])->values()->all();

        return array_merge($this->present($tenant), [
            'client_record' => $client?->present() ?? array_fill_keys(SchoolClient::FIELDS, null),
            'payments' => SchoolPayment::query()->where('school_id', $tenant->id)->orderBy('paid_on', 'desc')->limit(100)->get()
                ->map(fn (SchoolPayment $p) => $p->present())->values()->all(),
            'licence_history' => $history,
            'support_grants' => $stats !== null ? $stats->grants : [],
            'security' => $stats !== null ? $stats->security : null,
            'alerts' => PlatformAlert::query()->where('school_id', $tenant->id)->orderBy('raised_at', 'desc')->limit(20)->get()
                ->map(fn (PlatformAlert $a) => $a->present())->values()->all(),
        ]);
    }
}
