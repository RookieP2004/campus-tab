<?php

namespace App\Modules\Leave\Http\Controllers;

use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Leave\Domain\LeaveBalances;
use App\Modules\Leave\Domain\LeaveViews;
use App\Modules\Leave\Domain\LeaveWorkflow;
use App\Modules\Leave\Models\LeaveDelegation;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\People\Models\Staff;
use App\Support\Http\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Leave set-up and the views around requests.
 *
 *   GET   /leave-types                              active types (and archived ones for the Principal)
 *   POST  /leave-types { code, name, quota?, sandwich, half_day, document_after_days? }
 *   PATCH /leave-types/{id}                         change, or archive with status
 *   GET   /leave/balances/me                        my balance per type this year
 *   GET   /leave/calendar ?from&to                  who is away (names and dates only)
 *   GET   /leave/cover ?from&to                     the needs-cover list from approved leave
 *   GET   /leave/delegations · POST { delegate_user_id, from, to } · POST /leave/delegations/{id}/end
 */
class LeaveSetupController
{
    public function types(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $all = Gate::forUser($user)->allows('manageTypes', LeaveType::class);
        $query = LeaveType::query()->orderBy('code');

        if (! $all) {
            $query->where('status', 'active');
        }

        return response()->json(['data' => $query->get()->map(fn (LeaveType $t) => self::presentType($t))->values(), 'can' => ['manage' => $all]]);
    }

    public function storeType(Request $request): JsonResponse
    {
        Gate::authorize('manageTypes', LeaveType::class);
        $input = $this->typeInput($request, true);

        if (LeaveType::query()->where('code', $input['code'])->exists()) {
            throw new ApiException('leave_type_exists', 'A leave type with this code already exists.', 422);
        }

        return response()->json(['data' => self::presentType(LeaveType::create($input))], 201);
    }

    /** Adds the starter types the school does not have yet (codes already used are left alone). */
    public function starterTypes(): JsonResponse
    {
        Gate::authorize('manageTypes', LeaveType::class);

        return response()->json(['data' => self::addStarterTypes()], 201);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function addStarterTypes(): array
    {
        $added = [];
        foreach ((array) config('leave.starter_types', []) as $starter) {
            if (! LeaveType::query()->where('code', $starter['code'])->exists()) {
                $added[] = self::presentType(LeaveType::create($starter));
            }
        }

        return $added;
    }

    public function updateType(Request $request, string $id): JsonResponse
    {
        Gate::authorize('manageTypes', LeaveType::class);
        $type = LeaveType::query()->findOrFail($id);
        $input = $this->typeInput($request, false);
        unset($input['code']); // the code is what the ledger and requests refer to
        $type->fill($input)->save();

        return response()->json(['data' => self::presentType($type)]);
    }

    public function myBalances(Request $request, LeaveBalances $balances, LeaveWorkflow $workflow): JsonResponse
    {
        $user = $this->user($request);
        $staff = Staff::query()->where('user_id', (string) $user->getKey())->where('status', Staff::STATUS_ACTIVE)->first()
            ?? throw new ApiException('not_staff', 'Only staff have leave balances.', 403);

        return response()->json(['data' => $balances->all((string) $staff->getKey(), $workflow->yearId())]);
    }

    public function calendar(Request $request, LeaveViews $views): JsonResponse
    {
        [$from, $to] = $this->range($request);

        return response()->json(['data' => $views->calendar($this->user($request), $from, $to), 'from' => $from, 'to' => $to]);
    }

    public function cover(Request $request): JsonResponse
    {
        Gate::authorize('viewCover', LeaveRequest::class);
        [$from, $to] = $this->range($request, 7);

        $rows = [];
        $leave = LeaveRequest::query()->where('kind', 'staff')->where('status', LeaveRequest::APPROVED)->where('from', '<=', $to)->where('to', '>=', $from)->get();
        foreach ($leave as $r) {
            foreach ($r->cover_needed as $row) {
                if ($row['date'] >= $from && $row['date'] <= $to) {
                    $rows[] = array_merge($row, ['teacher' => $r->applicant['name']]);
                }
            }
        }
        usort($rows, fn ($a, $b) => [$a['date'], $a['section']] <=> [$b['date'], $b['section']]);

        return response()->json(['data' => $rows, 'from' => $from, 'to' => $to]);
    }

    public function delegations(Request $request): JsonResponse
    {
        Gate::authorize('delegate', LeaveDelegation::class);
        $deputies = User::query()->where('roles', Role::VicePrincipal->value)->where('status', User::STATUS_ACTIVE)->get(['name']);

        return response()->json([
            'data' => LeaveDelegation::query()->orderBy('from', 'desc')->limit(50)->get()->map(fn (LeaveDelegation $d) => self::presentDelegation($d))->values(),
            'deputies' => $deputies->map(fn (User $u) => ['id' => (string) $u->getKey(), 'name' => $u->name])->values(),
        ]);
    }

    public function delegate(Request $request): JsonResponse
    {
        Gate::authorize('delegate', LeaveDelegation::class);
        $user = $this->user($request);
        $input = $request->validate([
            'delegate_user_id' => ['required', 'string', 'max:30'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $deputy = User::query()->find($input['delegate_user_id']);
        if (! $deputy instanceof User || ! $deputy->isActive() || ! $deputy->hasRole(Role::VicePrincipal) || (string) $deputy->getKey() === (string) $user->getKey()) {
            throw new ApiException('delegate_invalid', 'Leave decisions can be handed only to an active Vice Principal.', 422);
        }
        if (CarbonImmutable::parse($input['from'])->diffInDays(CarbonImmutable::parse($input['to'])) > 90) {
            throw new ApiException('delegation_too_long', 'A delegation can last at most 90 days.', 422);
        }

        $delegation = LeaveDelegation::create([
            'delegate' => ['id' => (string) $deputy->getKey(), 'name' => $deputy->name],
            'delegate_id' => (string) $deputy->getKey(),
            'by' => ['id' => (string) $user->getKey(), 'name' => $user->name],
            'from' => $input['from'], 'to' => $input['to'],
        ]);

        return response()->json(['data' => self::presentDelegation($delegation)], 201);
    }

    public function endDelegation(Request $request, string $id): JsonResponse
    {
        Gate::authorize('delegate', LeaveDelegation::class);
        $user = $this->user($request);
        $delegation = LeaveDelegation::query()->findOrFail($id);

        if ($delegation->ended_at === null) {
            $delegation->fill(['ended_at' => now()->toIso8601String(), 'ended_by' => ['id' => (string) $user->getKey(), 'name' => $user->name]])->save();
        }

        return response()->json(['data' => self::presentDelegation($delegation)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function typeInput(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'code' => [$required, 'string', 'regex:/^[A-Z][A-Z0-9_]{0,9}$/'],
            'name' => [$required, 'string', 'min:2', 'max:60'],
            'quota' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:366'],
            'sandwich' => [$required, 'boolean'],
            'half_day' => [$required, 'boolean'],
            'document_after_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:60'],
            'status' => ['sometimes', Rule::in(['active', 'archived'])],
        ]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function range(Request $request, int $defaultDays = 31): array
    {
        $input = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $from = $input['from'] ?? now('Asia/Kolkata')->toDateString();
        $to = $input['to'] ?? CarbonImmutable::parse($from)->addDays($defaultDays - 1)->toDateString();

        if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > 92) {
            throw new ApiException('range_too_long', 'Choose at most three months.', 422);
        }

        return [$from, $to];
    }

    /**
     * @return array<string, mixed>
     */
    public static function presentType(LeaveType $t): array
    {
        return [
            'id' => (string) $t->getKey(), 'code' => $t->code, 'name' => $t->name, 'quota' => $t->quota === null ? null : (float) $t->quota,
            'sandwich' => $t->sandwich, 'half_day' => $t->half_day, 'document_after_days' => $t->document_after_days, 'status' => $t->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function presentDelegation(LeaveDelegation $d): array
    {
        $today = now('Asia/Kolkata')->toDateString();

        return [
            'id' => (string) $d->getKey(), 'delegate' => $d->delegate['name'], 'delegate_user_id' => $d->delegate_id ?? $d->delegate['id'], 'by' => $d->by['name'],
            'from' => $d->from, 'to' => $d->to, 'ended_at' => $d->ended_at, 'active' => $d->activeOn($today),
            'state' => $d->ended_at !== null ? 'ended' : ($d->to < $today ? 'over' : ($d->from > $today ? 'upcoming' : 'active')),
        ];
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new ApiException('school_account_required', 'Sign in with a school account to continue.', 403);
    }
}
