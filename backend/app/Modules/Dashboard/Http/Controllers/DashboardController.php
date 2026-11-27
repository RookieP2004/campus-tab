<?php

namespace App\Modules\Dashboard\Http\Controllers;

use App\Modules\Dashboard\Domain\CardProvider;
use App\Modules\Dashboard\Domain\DashboardContext;
use App\Modules\Identity\Domain\AccessControl;
use App\Modules\Identity\Domain\RoleRegistry;
use App\Modules\Identity\Models\User;
use App\Modules\People\Domain\StudentRecords;
use App\Modules\People\Models\Staff;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * My dashboard and search.
 *
 *   GET /me/dashboard ?as=<role>   the cards for one of my roles (the role switcher), built on
 *                                  the server from what that role may see; the screen never filters
 *   GET /me/search ?q=             students and staff I may see, for Ctrl/Cmd K
 */
class DashboardController
{
    public function show(Request $request, AccessControl $access, RoleRegistry $registry): JsonResponse
    {
        $user = $this->user($request);
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');
        $as = $request->query('as');
        $context = DashboardContext::for($user, $tenant, is_string($as) ? $as : null, $access);

        $cards = [];
        foreach ((array) config('dashboard.providers', []) as $class) {
            /** @var CardProvider $provider */
            $provider = app($class);
            array_push($cards, ...$provider->cards($user, $context));
        }

        usort($cards, fn (array $a, array $b) => $a['priority'] <=> $b['priority']);

        $levelRoles = array_values(array_filter($user->roles, fn (string $r) => $registry->level($r) !== null));

        return response()->json(['data' => [
            'role' => $context->role,
            'roles' => array_map(fn (string $r) => ['name' => $r, 'label' => $registry->find($r)->label ?? $r], $levelRoles),
            'cards' => $cards,
        ]]);
    }

    public function search(Request $request, AccessControl $access, StudentRecords $students): JsonResponse
    {
        $input = $request->validate(['q' => ['required', 'string', 'min:2', 'max:60']]);
        $user = $this->user($request);
        $results = [];

        if ($access->holds($user, 'students.view')) {
            foreach ($students->list($user, ['q' => $input['q'], 'per_page' => 8])['rows'] as $row) {
                $results[] = ['type' => 'student', 'label' => $row['name'], 'detail' => trim(($row['section_label'] ?? '').' · '.$row['admission_no'], ' ·'), 'link' => '/students/'.$row['id']];
            }
        }

        if ($access->allows($user, 'staff.view')) {
            $pattern = '/'.preg_quote($input['q'], '/').'/i';
            foreach (Staff::query()->where('status', Staff::STATUS_ACTIVE)->where('name', 'regex', $pattern)->limit(6)->get() as $staff) {
                $results[] = ['type' => 'staff', 'label' => $staff->name, 'detail' => $staff->designation, 'link' => '/staff/'.$staff->getKey()];
            }
        }

        return response()->json(['data' => $results]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new ApiException('school_account_required', 'Sign in with a school account to continue.', 403);
    }
}
