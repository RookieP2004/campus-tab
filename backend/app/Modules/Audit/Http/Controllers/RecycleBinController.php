<?php

namespace App\Modules\Audit\Http\Controllers;

use App\Modules\Audit\Domain\RecycleBin;
use App\Modules\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The recycle bin.
 *
 *   GET    /recycle-bin?type=user          deleted records (Principal and Chief)
 *   POST   /recycle-bin/{type}/{id}/restore
 *   DELETE /recycle-bin/{type}/{id}        { confirm: "<exact name>" }  Chief only,
 *                                          step-up permanent_delete, after 30 days
 */
class RecycleBinController
{
    public function __construct(private readonly RecycleBin $bin) {}

    public function index(Request $request): JsonResponse
    {
        $this->need($request, 'records.restore');
        $input = $request->validate(['type' => ['nullable', 'string', 'max:40']]);

        return response()->json(['data' => $this->bin->list($input['type'] ?? null)]);
    }

    public function restore(Request $request, string $type, string $id): JsonResponse
    {
        $actor = $this->need($request, 'records.restore');
        $model = $this->bin->restore($actor, $type, $id);

        return response()->json(['data' => ['type' => $type, 'id' => (string) $model->getKey(), 'restored' => true]]);
    }

    public function purge(Request $request, string $type, string $id): JsonResponse
    {
        $actor = $this->need($request, 'records.purge');
        $input = $request->validate(['confirm' => ['required', 'string', 'max:200']]);

        $this->bin->purge($actor, $type, $id, $input['confirm']);

        return response()->json(['data' => ['type' => $type, 'id' => $id, 'purged' => true]]);
    }

    /** Gates `records.restore` and `records.purge` are defined in AuditServiceProvider. */
    private function need(Request $request, string $ability): User
    {
        Gate::authorize($ability);

        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
