<?php

namespace App\Modules\People\Http\Controllers;

use App\Modules\AcademicStructure\Domain\Structure;
use App\Modules\AcademicStructure\Models\AcademicYear;
use App\Modules\AcademicStructure\Models\Section;
use App\Modules\AcademicStructure\Models\TeachingAssignment;
use App\Modules\Identity\Domain\AccessControl;
use App\Modules\Identity\Models\User;
use App\Modules\People\Domain\StaffRecords;
use App\Modules\People\Models\Staff;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Staff records.
 *
 *   GET   /staff ?status ?q                 the list (drafts included for those who approve)
 *   POST  /staff                            create (Chief/Principal: at once, with the account);
 *                                           a Clerk's request becomes a draft for the Principal
 *   GET   /staff/{id}                       profile, account, class and subjects this year
 *   PATCH /staff/{id}                       edit details and a Vice Principal's wings
 *   POST  /staff/{id}/approve               the Principal approves a draft: the account is made now
 *   POST  /staff/{id}/reject { reason }     the Principal rejects a draft
 *   POST  /staff/{id}/deactivate            access removed, record kept
 */
class StaffController
{
    public function __construct(private readonly StaffRecords $records) {}

    public function index(Request $request, AccessControl $access): JsonResponse
    {
        Gate::authorize('viewAnyStaff', Staff::class);

        $query = Staff::query()->orderBy('name');
        $status = $request->query('status');

        if (is_string($status) && in_array($status, [Staff::STATUS_DRAFT, Staff::STATUS_ACTIVE, Staff::STATUS_INACTIVE], true)) {
            $query->where('status', $status);
        }

        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $pattern = '/'.preg_quote($q, '/').'/i';
            $query->where(fn ($w) => $w->where('name', 'regex', $pattern)->orWhere('employee_code', 'regex', $pattern));
        }

        $users = User::query()->whereIn('_id', $query->clone()->pluck('user_id')->filter()->values()->all())->get()->keyBy(fn (User $u) => (string) $u->getKey());

        return response()->json([
            'data' => $query->get()->map(fn (Staff $s) => $this->row($s, $users[$s->user_id ?? ''] ?? null))->values(),
            'can' => [
                'create' => $access->allows($this->user($request), 'staff.create'),
                'draft' => $access->allows($this->user($request), 'staff.draft'),
                'approve' => $access->allows($this->user($request), 'staff.approve'),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('createStaff', Staff::class);

        $input = $request->validate([
            'employee_code' => ['nullable', 'string', 'max:30'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'designation' => ['nullable', 'string', 'max:80'],
            'type' => ['sometimes', Rule::in(['teaching', 'non_teaching'])],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:20'],
            'joined_on' => ['nullable', 'date_format:Y-m-d'],
            'roles' => ['sometimes', 'array', 'max:5'],
            'roles.*' => ['string', 'max:40'],
            'wings' => ['sometimes', 'array'],
            'wings.*' => ['string', 'max:40'],
        ]);

        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');
        $result = $this->records->create($this->user($request), $tenant, $input);

        return response()->json(['data' => array_merge($this->profile($result['staff']), ['password_setup' => $result['password_setup']])], 201);
    }

    public function show(string $id): JsonResponse
    {
        $staff = Staff::query()->findOrFail($id);
        Gate::authorize('view', $staff);

        return response()->json(['data' => $this->profile($staff)]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $staff = Staff::query()->findOrFail($id);
        Gate::authorize('update', $staff);

        $input = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'designation' => ['sometimes', 'nullable', 'string', 'max:80'],
            'type' => ['sometimes', Rule::in(['teaching', 'non_teaching'])],
            'joined_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'wings' => ['sometimes', 'array'],
            'wings.*' => ['string', 'max:40'],
        ]);

        $staff->fill($input)->save();

        return response()->json(['data' => $this->profile($staff)]);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $staff = Staff::query()->findOrFail($id);
        Gate::authorize('approve', $staff);

        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');
        $setup = $this->records->approve($this->user($request), $tenant, $staff);

        return response()->json(['data' => array_merge($this->profile(Staff::query()->findOrFail($id)), ['password_setup' => $setup])]);
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $staff = Staff::query()->findOrFail($id);
        Gate::authorize('approve', $staff);
        $input = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']]);

        $this->records->reject($this->user($request), $staff, $input['reason']);

        return response()->json(['data' => ['rejected' => true]]);
    }

    public function deactivate(Request $request, string $id): JsonResponse
    {
        $staff = Staff::query()->findOrFail($id);
        Gate::authorize('deactivate', $staff);
        $this->records->deactivate($this->user($request), $staff);

        return response()->json(['data' => $this->profile($staff)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Staff $s, ?User $user): array
    {
        return [
            'id' => (string) $s->getKey(),
            'employee_code' => $s->employee_code,
            'name' => $s->name,
            'designation' => $s->designation,
            'type' => $s->type,
            'email' => $s->email,
            'phone' => $s->phone,
            'status' => $s->status,
            'joined_on' => $s->joined_on,
            'wings' => $s->wings,
            'roles' => $user !== null ? $user->roles : [],
            'account' => $user === null ? null : ['status' => $user->status, 'mfa' => $user->mfaEnabled()],
            'requested_roles' => $s->requested_roles,
            'drafted_by' => $s->drafted_by,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function profile(Staff $staff): array
    {
        $user = is_string($staff->user_id) ? User::query()->find($staff->user_id) : null;
        $year = AcademicYear::current();
        $classOf = [];
        $teaches = [];

        if ($year instanceof AcademicYear) {
            $classOf = Section::query()->where('year_id', (string) $year->getKey())->where('class_teacher_id', (string) $staff->getKey())->pluck('label')->all();
            $assignments = TeachingAssignment::query()->where('year_id', (string) $year->getKey())->where('teacher_id', (string) $staff->getKey())->get();
            $teaches = app(Structure::class)->presentAssignments($assignments);
        }

        return array_merge($this->row($staff, $user instanceof User ? $user : null), [
            'class_teacher_of' => $classOf,
            'teaches' => $teaches,
            'custom' => $staff->custom,
        ]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new ApiException('school_account_required', 'Sign in with a school account to continue.', 403);
    }
}
