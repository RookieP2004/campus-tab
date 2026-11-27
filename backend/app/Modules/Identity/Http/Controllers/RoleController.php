<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Domain\PermissionCatalogue;
use App\Modules\Identity\Domain\RoleAdmin;
use App\Modules\Identity\Domain\RoleRegistry;
use App\Modules\Identity\Models\SchoolRole;
use App\Modules\Identity\Models\User;
use App\Support\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Roles and the permission matrix of the signed-in school.
 *
 *   GET  /roles          every role with its permissions
 *   GET  /permissions    every permission string the code knows
 *   POST /roles          { name, label, level, permissions[] }   (step-up role_edit)
 *   PUT  /roles/{name}   { permissions[], label? }               (step-up role_edit)
 */
class RoleController
{
    public function index(RoleRegistry $roles): JsonResponse
    {
        Gate::authorize('viewAny', SchoolRole::class);

        return response()->json(['data' => array_values(array_map(fn (SchoolRole $r) => self::present($r), $roles->all()))]);
    }

    public function catalogue(): JsonResponse
    {
        Gate::authorize('viewAny', SchoolRole::class);

        return response()->json(['data' => [
            'scopes' => config('permissions.scopes'),
            'abilities' => PermissionCatalogue::abilities(),
        ]]);
    }

    public function store(Request $request, RoleAdmin $admin): JsonResponse
    {
        Gate::authorize('edit', SchoolRole::class);

        $input = $request->validate([
            'name' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{2,39}$/'],
            'label' => ['required', 'string', 'min:2', 'max:60'],
            'level' => ['required', 'integer', 'min:1', 'max:89'],
            'permissions' => ['present', 'array', 'max:200'],
            'permissions.*' => ['string', 'max:80'],
        ]);

        /** @var User $actor */
        $actor = $request->user();

        return response()->json(['data' => self::present($admin->create($actor, $input))], 201);
    }

    public function update(Request $request, string $name, RoleAdmin $admin, RoleRegistry $roles): JsonResponse
    {
        Gate::authorize('edit', SchoolRole::class);

        $input = $request->validate([
            'label' => ['nullable', 'string', 'min:2', 'max:60'],
            'permissions' => ['present', 'array', 'max:200'],
            'permissions.*' => ['string', 'max:80'],
        ]);

        $role = $roles->find($name) ?? throw new ApiException('not_found', 'The record was not found.', 404);

        /** @var User $actor */
        $actor = $request->user();

        return response()->json(['data' => self::present($admin->update($actor, $role, $input['permissions'], $input['label'] ?? null))]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function present(SchoolRole $role): array
    {
        return [
            'name' => $role->name,
            'label' => $role->label,
            'level' => $role->level,
            'duty' => $role->duty,
            'is_system' => $role->is_system,
            'editable' => $role->name !== 'chief',
            'permissions' => array_values($role->permissions ?? []),
        ];
    }
}
