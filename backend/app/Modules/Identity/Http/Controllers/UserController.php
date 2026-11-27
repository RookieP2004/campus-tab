<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Domain\UserAdmin;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Staff accounts of the signed-in school.
 *
 *   GET  /users                         list, with status (invited, active) and locked state
 *   POST /users                         { name, email?, phone?, username?, roles[] }
 *                                       -> status "invited", no password; a set-password link goes by
 *                                          email or SMS, or a one-time setup code is returned once
 *   PUT  /users/{id}/roles              { roles[] }        (step-up role_edit)
 *   POST /users/{id}/deactivate         "delete staff": recycle bin, devices signed out
 *   POST /users/{id}/unlock
 *   POST /users/{id}/password-link      invited: resend the set-password link (or a new setup code);
 *                                       active: send a reset link. The old link stops working.
 *   POST /users/{id}/mfa-reset          lost authenticator (step-up mfa_reset)
 *
 * Nobody types or sees another person's password.
 */
class UserController
{
    public function __construct(private readonly UserAdmin $admin) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', User::class);

        $input = $request->validate(['role' => ['nullable', 'string', 'max:40']]);

        $users = User::query()
            ->when($input['role'] ?? null, fn ($q, $role) => $q->where('roles', $role))
            ->orderBy('name')
            ->limit(500)
            ->get();

        return response()->json(['data' => $users->map(fn (User $u) => self::present($u))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', User::class);

        $input = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['nullable', 'email', 'max:150', 'required_without_all:phone,username'],
            'phone' => ['nullable', 'regex:/^\+?[0-9]{10,15}$/', 'required_without_all:email,username'],
            'username' => ['nullable', 'string', 'regex:/^[a-z][a-z0-9._]{2,39}$/i', 'required_without_all:email,phone'],
            'roles' => ['required', 'array', 'min:1', 'max:10'],
            'roles.*' => ['string', 'max:40'],
        ], [
            'username.regex' => 'A login name uses letters, digits, dots and underscores, and starts with a letter.',
        ]);

        $this->assertUnique($input['email'] ?? null, $input['phone'] ?? null, $input['username'] ?? null);

        $result = $this->admin->create($this->actor($request), $this->tenant($request), $input);

        return response()->json(['data' => array_merge(self::present($result['user']), [
            'password_setup' => $result['password_setup'],
        ])], 201);
    }

    public function updateRoles(Request $request, string $id): JsonResponse
    {
        $target = $this->find($id);
        Gate::authorize('changeRoles', $target);

        $input = $request->validate([
            'roles' => ['required', 'array', 'min:1', 'max:10'],
            'roles.*' => ['string', 'max:40'],
        ]);

        return response()->json(['data' => self::present($this->admin->changeRoles($this->actor($request), $target, $input['roles']))]);
    }

    public function deactivate(Request $request, string $id): JsonResponse
    {
        $target = $this->find($id);
        Gate::authorize('deactivate', $target);

        $this->admin->deactivate($this->actor($request), $target);

        return response()->json(['data' => ['deactivated' => true, 'in_recycle_bin' => true]]);
    }

    public function unlock(Request $request, string $id): JsonResponse
    {
        $target = $this->find($id);
        Gate::authorize('unlock', $target);

        $this->admin->unlock($this->actor($request), $target);

        // Reloaded through the scoped query: Model::refresh() skips the tenant scope.
        return response()->json(['data' => self::present($this->find($id))]);
    }

    public function passwordLink(Request $request, string $id): JsonResponse
    {
        $target = $this->find($id);
        Gate::authorize('sendPasswordLink', $target);

        $setup = $this->admin->sendPasswordLink($this->actor($request), $this->tenant($request), $target);

        return response()->json(['data' => ['password_setup' => $setup]], 202);
    }

    public function resetMfa(Request $request, string $id): JsonResponse
    {
        $target = $this->find($id);
        Gate::authorize('resetMfa', $target);

        $this->admin->resetMfa($this->actor($request), $target);

        return response()->json(['data' => self::present($this->find($id))]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(User $user): array
    {
        return [
            'id' => (string) $user->getKey(),
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'username' => $user->username,
            'roles' => $user->roles,
            'status' => $user->status,
            'locked' => $user->isLocked(),
            'locked_until' => $user->isLocked() ? $user->locked_until?->toIso8601String() : null,
            'mfa_enabled' => $user->mfaEnabled(),
            'has_password' => is_string($user->password) && $user->password !== '',
            'last_sign_in_at' => $user->last_sign_in_at?->toIso8601String(),
        ];
    }

    private function find(string $id): User
    {
        $user = User::query()->find($id);

        return $user instanceof User ? $user : throw new ApiException('not_found', 'The record was not found.', 404);
    }

    private function assertUnique(?string $email, ?string $phone, ?string $username): void
    {
        $checks = array_filter([
            'email' => $email !== null ? mb_strtolower($email) : null,
            'phone' => $phone,
            'username' => $username !== null ? mb_strtolower($username) : null,
        ]);

        foreach ($checks as $field => $value) {
            if (User::withTrashed()->where($field, $value)->exists()) {
                throw new ApiException('user_exists', "An account with this {$field} already exists in this school (it may be in the recycle bin).", 422, ['field' => $field]);
            }
        }
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    private function tenant(Request $request): Tenant
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');

        return $tenant;
    }
}
