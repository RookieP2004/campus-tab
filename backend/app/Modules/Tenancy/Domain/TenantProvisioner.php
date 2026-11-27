<?php

namespace App\Modules\Tenancy\Domain;

use App\Modules\Audit\Domain\AuditRecorder;
use App\Modules\Identity\Domain\SystemRoles;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Validator;

/**
 * Platform side: creates a school record in "pending" state, with its
 * system roles. The school becomes active when its contact activates the
 * licence key.
 */
class TenantProvisioner
{
    /**
     * @param  array{code: string, name: string, email?: string|null, phone?: string|null}  $input
     */
    public function create(array $input): Tenant
    {
        $input['code'] = Tenant::normaliseCode($input['code']);

        Validator::make($input, [
            'code' => ['required', 'string', 'min:3', 'max:30', 'regex:/^[A-Z0-9]+(-[A-Z0-9]+)*$/'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['nullable', 'email', 'required_without:phone'],
            'phone' => ['nullable', 'regex:/^\+?[0-9]{10,15}$/', 'required_without:email'],
        ], [
            'code.regex' => 'The school code may use letters, digits and single dashes, e.g. SUNRISE-BPL.',
        ])->after(function ($validator) use ($input): void {
            if (Tenant::findByCode($input['code']) !== null) {
                $validator->errors()->add('code', 'This school code is already taken.');
            }
        })->validate();

        $tenant = Tenant::create([
            'code' => $input['code'],
            'name' => $input['name'],
            'contact' => [
                'email' => isset($input['email']) ? mb_strtolower($input['email']) : null,
                'phone' => $input['phone'] ?? null,
            ],
        ]);

        // Every school starts with the system roles (nine levels, two duties) and the default matrix.
        app(TenantContext::class)->run($tenant->id, function (): void {
            $created = AuditRecorder::muted(fn () => SystemRoles::seed());
            app(AuditRecorder::class)->record('roles.seeded', 'admin', 'roles', null, meta: ['created' => $created]);
        });

        return $tenant;
    }
}
