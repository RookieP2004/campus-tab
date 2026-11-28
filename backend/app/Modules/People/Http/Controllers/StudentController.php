<?php

namespace App\Modules\People\Http\Controllers;

use App\Modules\AcademicStructure\Models\Section;
use App\Modules\Customisation\Domain\FieldRules;
use App\Modules\Customisation\Models\CustomField;
use App\Modules\Identity\Models\User;
use App\Modules\Licensing\Domain\Seats;
use App\Modules\People\Domain\ParentLogins;
use App\Modules\People\Domain\StudentLogins;
use App\Modules\People\Domain\StudentRecords;
use App\Modules\People\Models\Consent;
use App\Modules\People\Models\Guardian;
use App\Modules\People\Models\Student;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Students and their guardians.
 *
 *   GET    /students ?section_id ?q ?page ?per_page   rows you may see, with your custom columns
 *   GET    /students/seats                           student seats used and left, with staff seats
 *   POST   /students                                 admit (seat check, duplicate check, consent for PEN/APAAR)
 *   GET    /students/{id}                            profile: enrolment, guardians, consents, custom values
 *   PATCH  /students/{id}                            edit (a class teacher: roll number and columns only)
 *   DELETE /students/{id}                            to the recycle bin
 *   POST   /students/{id}/guardians                  add or link a guardian (same phone = same guardian)
 *   PATCH  /guardians/{id}                           edit a guardian
 *   POST   /guardians/{id}/parent-login              turn parent login on (phone required, SMS-code sign-in)
 *   DELETE /guardians/{id}/parent-login              turn it off
 *   POST   /sections/{id}/student-setup-codes         one-time setup codes for the class (48 hours)
 */
class StudentController
{
    public function __construct(
        private readonly StudentRecords $records,
        private readonly FieldRules $fields,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAnyStudents', Student::class);

        $input = $request->validate([
            'section_id' => ['nullable', 'string', 'max:30'],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $user = $this->user($request);
        $result = $this->records->list($user, $input);

        return response()->json([
            'data' => $result['rows'],
            'columns' => array_map(fn (CustomField $f) => $this->column($user, $f), $result['columns']),
            'meta' => ['total' => $result['total'], 'page' => (int) ($input['page'] ?? 1), 'per_page' => (int) ($input['per_page'] ?? 100)],
        ]);
    }

    public function seats(Seats $seats): JsonResponse
    {
        Gate::authorize('viewAnyStudents', Student::class);

        // Students first (the list's own limit), with staff alongside.
        return response()->json(['data' => array_merge($seats->status('students'), ['staff' => $seats->status('staff')])]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('admit', Student::class);

        $input = $request->validate([
            'admission_no' => ['required', 'string', 'max:30'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'dob' => ['required', 'date_format:Y-m-d', 'before:today'],
            'gender' => ['required', Rule::in(['female', 'male', 'other'])],
            'section_id' => ['required', 'string', 'max:30'],
            'roll_no' => ['nullable', 'integer', 'min:1', 'max:999'],
            'guardians' => ['sometimes', 'array', 'max:4'],
            'guardians.*.name' => ['required', 'string', 'max:120'],
            'guardians.*.relation' => ['nullable', 'string', 'max:30'],
            'guardians.*.phone' => ['nullable', 'string', 'max:20'],
            'guardians.*.email' => ['nullable', 'email', 'max:150'],
            'ids' => ['sometimes', 'array'],
            'ids.pen' => ['nullable', 'string', 'max:20'],
            'ids.apaar' => ['nullable', 'string', 'max:20'],
            'ids.aadhaar_last4' => ['nullable', 'string', 'max:4'],
            'consents' => ['sometimes', 'array'],
            'consents.*' => ['boolean'],
            'custom' => ['sometimes', 'array'],
            'confirm_not_duplicate' => ['sometimes', 'boolean'],
        ]);

        $student = $this->records->admit($this->user($request), $input);

        return response()->json(['data' => $this->profile($request, Student::query()->findOrFail($student->getKey()))], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $student = Student::query()->findOrFail($id);
        Gate::authorize('view', $student);

        return response()->json(['data' => $this->profile($request, $student)]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $student = Student::query()->findOrFail($id);
        Gate::authorize('update', $student);

        $input = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'dob' => ['sometimes', 'date_format:Y-m-d', 'before:today'],
            'gender' => ['sometimes', Rule::in(['female', 'male', 'other'])],
            'status' => ['sometimes', Rule::in(['active', 'left'])],
            'roll_no' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:999'],
            'ids' => ['sometimes', 'array'],
            'ids.pen' => ['nullable', 'string', 'max:20'],
            'ids.apaar' => ['nullable', 'string', 'max:20'],
            'ids.aadhaar_last4' => ['nullable', 'string', 'max:4'],
            'consents' => ['sometimes', 'array'],
            'consents.*' => ['boolean'],
            'custom' => ['sometimes', 'array'],
        ]);

        $this->records->update($this->user($request), $student, $input);

        return response()->json(['data' => $this->profile($request, Student::query()->findOrFail($id))]);
    }

    public function destroy(string $id): JsonResponse
    {
        $student = Student::query()->findOrFail($id);
        Gate::authorize('delete', $student);
        $student->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function addGuardian(Request $request, string $id): JsonResponse
    {
        Gate::authorize('manageGuardians', Student::class);
        $student = Student::query()->findOrFail($id);

        $input = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'relation' => ['nullable', 'string', 'max:30'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:150'],
        ]);

        $guardian = $this->records->linkGuardian($student, $input);

        if (! in_array((string) $guardian->getKey(), $student->guardian_ids, true)) {
            $student->forceFill(['guardian_ids' => [...$student->guardian_ids, (string) $guardian->getKey()]])->save();
        }

        return response()->json(['data' => $this->profile($request, Student::query()->findOrFail($id))], 201);
    }

    public function updateGuardian(Request $request, string $id): JsonResponse
    {
        Gate::authorize('manageGuardians', Student::class);
        $guardian = Guardian::query()->findOrFail($id);

        $input = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'relation' => ['sometimes', 'nullable', 'string', 'max:30'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'email' => ['sometimes', 'nullable', 'email', 'max:150'],
        ]);

        if (array_key_exists('phone', $input)) {
            $input['phone'] = StudentRecords::phone($input['phone']);

            if (is_string($guardian->user_id) && $input['phone'] !== $guardian->phone) {
                throw new ApiException('parent_login_phone', 'Turn the parent login off before changing the phone it signs in with.', 409);
            }
        }

        $guardian->fill($input)->save();

        return response()->json(['data' => self::guardian($guardian)]);
    }

    public function enableParentLogin(Request $request, string $id, ParentLogins $logins): JsonResponse
    {
        Gate::authorize('manageGuardians', Student::class);
        $guardian = Guardian::query()->findOrFail($id);
        $user = $logins->enable($this->user($request), $guardian);

        return response()->json(['data' => array_merge(self::guardian($guardian), [
            'parent_login' => ['user_id' => (string) $user->getKey(), 'phone' => $user->phone, 'children' => count($guardian->student_ids)],
        ])], 201);
    }

    public function disableParentLogin(Request $request, string $id, ParentLogins $logins): JsonResponse
    {
        Gate::authorize('manageGuardians', Student::class);
        $guardian = Guardian::query()->findOrFail($id);
        $logins->disable($this->user($request), $guardian);

        return response()->json(['data' => self::guardian($guardian)]);
    }

    public function setupCodes(Request $request, string $id, StudentLogins $logins): JsonResponse
    {
        $section = Section::query()->findOrFail($id);
        Gate::authorize('loginCodes', [Student::class, $section]);

        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');

        return response()->json(['data' => [
            'section' => ['id' => (string) $section->getKey(), 'label' => $section->label],
            'valid_hours' => (int) config('identity.passwords.set_link_hours', 48),
            'students' => $logins->issueCodes($this->user($request), $tenant, $section),
        ]], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function profile(Request $request, Student $student): array
    {
        $user = $this->user($request);
        $this->records->withEnrolment($student);
        $columns = $this->fields->visibleFields($user, ['student', 'enrolment'], $student->currentEnrolment?->section_id);
        $row = $this->records->row($student, $columns);
        $guardians = Guardian::query()->whereIn('_id', $student->guardian_ids)->get();

        $consents = [];
        foreach (Consent::PURPOSES as $purpose) {
            $latest = Consent::query()->where('student_id', (string) $student->getKey())->where('purpose', $purpose)->orderBy('created_at', 'desc')->first();
            // Only the parent's own answer counts; an office tick from before
            // Phase 9 shows as needing the parent's confirmation.
            $consents[$purpose] = $latest instanceof Consent ? [
                'given' => $latest->given && $latest->method === Consent::PARENT_ACCOUNT,
                'at' => $latest->at,
                'recorded_by' => $latest->recorded_by['name'] ?? null,
                'by_parent' => $latest->method === Consent::PARENT_ACCOUNT,
                'needs_parent' => Consent::needsParent((string) $student->getKey(), $purpose),
            ] : null;
        }

        $canSeeIds = Gate::allows('update', $student);

        return array_merge($row, [
            'ids' => $canSeeIds ? [
                'pen' => $student->ids['pen'] ?? null,
                'apaar' => $student->ids['apaar'] ?? null,
                'aadhaar_last4' => $student->ids['aadhaar_last4'] ?? null,
            ] : null,
            'login_name' => $student->login_name,
            'guardians' => $guardians->map(fn (Guardian $g) => self::guardian($g))->values()->all(),
            'consents' => $consents,
            'columns' => $columns->map(fn (CustomField $f) => $this->column($user, $f))->values()->all(),
            // Decrypted sensitive values, only for those who may fill them; the screen keeps them masked until "Show".
            'sensitive_values' => $this->records->revealed($user, $student, $columns),
            'can' => [
                'edit' => Gate::allows('update', $student),
                'manage_guardians' => Gate::allows('manageGuardians', Student::class),
                'delete' => Gate::allows('delete', $student),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function guardian(Guardian $g): array
    {
        return [
            'id' => (string) $g->getKey(),
            'name' => $g->name,
            'relation' => $g->relation,
            'phone' => $g->phone,
            'email' => $g->email,
            'student_ids' => $g->student_ids,
            'parent_login' => is_string($g->user_id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function column(User $user, CustomField $f): array
    {
        return [
            'key' => $f->key,
            'label' => $f->label,
            'type' => $f->type,
            'options' => $f->options,
            'entity' => $f->entity,
            'scope' => $f->scope,
            'owner_level' => $f->owner_level,
            'sensitive' => $f->sensitive,
            'can_fill' => $this->fields->canFill($user, $f),
        ];
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new ApiException('school_account_required', 'Sign in with a school account to continue.', 403);
    }
}
