<?php

namespace App\Modules\Approvals\Http\Controllers;

use App\Modules\Approvals\Domain\ApprovalSource;
use App\Modules\Identity\Models\User;
use App\Support\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /approvals/inbox — everything waiting on me, from every source in
 * config/approvals.php, oldest first, with a count per kind for the tabs.
 */
class ApprovalInboxController
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new ApiException('school_account_required', 'Sign in with a school account to continue.', 403);
        }

        $items = [];
        $counts = [];
        foreach ((array) config('approvals.sources', []) as $class) {
            $source = app($class);
            if (! $source instanceof ApprovalSource) {
                continue;
            }
            $mine = $source->itemsFor($user);
            $counts[$source->kind()] = ($counts[$source->kind()] ?? 0) + count($mine);
            $items = [...$items, ...$mine];
        }

        usort($items, fn (array $a, array $b) => ($a['at'] ?? '') <=> ($b['at'] ?? ''));

        return response()->json(['data' => $items, 'counts' => $counts, 'total' => count($items)]);
    }
}
