<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Domain\SchoolConfigService;
use App\Modules\Tenancy\Models\SchoolConfig;
use App\Support\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The signed-in school's config: current version, history, save, rollback.
 * Saves and rollbacks need If-Match with the version the user last saw
 * (the ETag of GET /config), so two people editing at once get a conflict.
 */
class SchoolConfigController
{
    public function __construct(private readonly SchoolConfigService $configs) {}

    public function show(): JsonResponse
    {
        Gate::authorize('view', SchoolConfig::class);

        return $this->respond($this->configs->current());
    }

    public function versions(): JsonResponse
    {
        Gate::authorize('view', SchoolConfig::class);

        return response()->json(['data' => $this->configs->history()->map(fn (SchoolConfig $c) => [
            'version' => $c->version,
            'diff' => $c->diff,
            'changed_by' => $c->changed_by,
            'reason' => $c->reason,
            'rolled_back_from' => $c->rolled_back_from,
            'created_at' => $c->created_at?->toIso8601String(),
        ])->values()]);
    }

    public function version(int $version): JsonResponse
    {
        Gate::authorize('view', SchoolConfig::class);

        return $this->respond($this->configs->version($version), withEtag: false);
    }

    public function update(Request $request): JsonResponse
    {
        Gate::authorize('view', SchoolConfig::class);

        $input = $request->validate([
            'body' => ['required', 'array'],
            'reason' => ['nullable', 'string', 'max:300'],
        ]);

        $saved = $this->configs->save($this->user($request), $input['body'], $this->expectedVersion($request), $input['reason'] ?? null);

        return $this->respond($saved);
    }

    public function rollback(Request $request, int $version): JsonResponse
    {
        Gate::authorize('view', SchoolConfig::class);

        $input = $request->validate(['reason' => ['nullable', 'string', 'max:300']]);

        $saved = $this->configs->rollback($this->user($request), $version, $this->expectedVersion($request), $input['reason'] ?? null);

        return $this->respond($saved);
    }

    private function respond(SchoolConfig $config, bool $withEtag = true): JsonResponse
    {
        $response = response()->json(['data' => [
            'version' => $config->version,
            'body' => $config->body,
            'changed_by' => $config->changed_by,
            'reason' => $config->reason,
            'rolled_back_from' => $config->rolled_back_from,
            'created_at' => $config->created_at?->toIso8601String(),
        ]]);

        return $withEtag ? $response->setEtag('v'.$config->version) : $response;
    }

    /** Reads If-Match: "v14" (quotes and a W/ prefix are accepted). */
    private function expectedVersion(Request $request): int
    {
        $header = trim((string) $request->header('If-Match'));

        if ($header === '') {
            throw new ApiException('precondition_required', 'Send If-Match with the version you last loaded.', 428);
        }

        if (preg_match('/^(?:W\/)?"?v?(\d+)"?$/', $header, $m) !== 1) {
            throw new ApiException('precondition_failed', 'If-Match must be the ETag of GET /config, for example "v3".', 412);
        }

        return (int) $m[1];
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
