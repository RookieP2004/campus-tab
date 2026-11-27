<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Domain\SupportAccess;
use App\Modules\Identity\Domain\SupportGrants;
use App\Modules\Identity\Models\SupportGrant;
use App\Modules\Identity\Models\User;
use App\Support\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The school's side of support access.
 *
 *   GET  /support-grants                 every grant, newest first
 *   POST /support-grants                 { agent_email, scope[], read_only?, reason, ends_at }  (step-up support_grant)
 *   GET  /support-grants/{id}/activity   everything done under that grant
 *   POST /support-grants/{id}/revoke     end it now
 */
class SupportGrantController
{
    public function __construct(private readonly SupportGrants $grants) {}

    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', SupportGrant::class);

        $grants = SupportGrant::query()->orderBy('created_at', 'desc')->limit(200)->get();

        return response()->json(['data' => $grants->map(fn (SupportGrant $g) => self::present($g))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('manage', SupportGrant::class);

        $input = $request->validate([
            'agent_email' => ['required', 'email', 'max:150'],
            'scope' => ['required', 'array', 'min:1'],
            'scope.*' => ['string', Rule::in(SupportAccess::areas())],
            'read_only' => ['sometimes', 'boolean'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'ends_at' => ['required', 'date'],
        ]);

        /** @var User $chief */
        $chief = $request->user();

        return response()->json(['data' => self::present($this->grants->create($chief, $input))], 201);
    }

    public function activity(string $id): JsonResponse
    {
        Gate::authorize('viewAny', SupportGrant::class);

        $grant = $this->find($id);

        $entries = AuditLog::query()
            ->where('support_grant_id', (string) $grant->getKey())
            ->orderBy('at', 'desc')
            ->limit(500)
            ->get();

        return response()->json(['data' => [
            'grant' => self::present($grant),
            'activity' => $entries->map(fn (AuditLog $e) => [
                'id' => (string) $e->getKey(),
                'at' => $e->at->toIso8601String(),
                'action' => $e->action,
                'entity' => $e->entity,
                'entity_id' => $e->entity_id,
                'actor' => $e->actor,
                'ip' => $e->ip,
            ])->values(),
        ]]);
    }

    public function revoke(Request $request, string $id): JsonResponse
    {
        Gate::authorize('manage', SupportGrant::class);

        /** @var User $chief */
        $chief = $request->user();

        return response()->json(['data' => self::present($this->grants->revoke($chief, $this->find($id)))]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(SupportGrant $grant): array
    {
        return [
            'id' => (string) $grant->getKey(),
            'agent' => ['id' => $grant->agent_id, 'name' => $grant->agent_name, 'email' => $grant->agent_email],
            'scope' => $grant->scope,
            'read_only' => $grant->read_only,
            'reason' => $grant->reason,
            'granted_by' => $grant->granted_by,
            'starts_at' => $grant->starts_at->toIso8601String(),
            'expires_at' => $grant->expires_at->toIso8601String(),
            'revoked_at' => $grant->revoked_at?->toIso8601String(),
            'revoked_by' => $grant->revoked_by,
            'status' => $grant->status(),
            'actions' => AuditLog::query()->where('support_grant_id', (string) $grant->getKey())->count(),
        ];
    }

    private function find(string $id): SupportGrant
    {
        $grant = SupportGrant::query()->find($id);

        return $grant instanceof SupportGrant ? $grant : throw new ApiException('not_found', 'The record was not found.', 404);
    }
}
