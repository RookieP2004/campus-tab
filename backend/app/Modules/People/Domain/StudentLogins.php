<?php

namespace App\Modules\People\Domain;

use App\Modules\AcademicStructure\Models\AcademicYear;
use App\Modules\AcademicStructure\Models\Section;
use App\Modules\Audit\Domain\AuditRecorder;
use App\Modules\Identity\Domain\PasswordLinks;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use App\Modules\People\Models\Enrolment;
use App\Modules\People\Models\Student;
use App\Modules\Tenancy\Domain\SchoolConfigService;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Http\ApiException;

/**
 * Student sign-in. A school setting, off by default
 * (sign_in.methods includes "student_login"). When it is on, the class
 * teacher prints one-time setup codes for the class: each student gets a
 * login name and a code, valid 48 hours, to choose their own password. The
 * codes are shown once (only a hash is stored) and never audited.
 */
class StudentLogins
{
    public const METHOD = 'student_login';

    public function __construct(
        private readonly SchoolConfigService $configs,
        private readonly PasswordLinks $links,
        private readonly AuditRecorder $audit,
    ) {}

    public function enabled(): bool
    {
        return in_array(self::METHOD, $this->configs->current()->body['sign_in']['methods'] ?? [], true);
    }

    /**
     * @return list<array{student_id: string, name: string, roll_no: int|null, login_name: string, code: string|null, expires_at: string}>
     */
    public function issueCodes(User $actor, Tenant $tenant, Section $section): array
    {
        if (! $this->enabled()) {
            throw new ApiException('student_login_off', 'Student sign-in is switched off for this school. The Chief Admin can turn it on in the school settings.', 409);
        }

        $year = AcademicYear::current();
        $enrolments = Enrolment::query()->where('year_id', (string) $year?->getKey())->where('section_id', (string) $section->getKey())->orderBy('roll_no')->get();
        $students = Student::query()->whereIn('_id', $enrolments->pluck('student_id')->all())->where('status', 'active')->get()->keyBy(fn (Student $s) => (string) $s->getKey());
        $hours = (int) config('identity.passwords.set_link_hours', 48);
        $sheet = [];

        foreach ($enrolments as $enrolment) {
            $student = $students[$enrolment->student_id] ?? null;

            if (! $student instanceof Student) {
                continue;
            }

            $user = $this->accountFor($student);
            $setup = $this->links->send($tenant, $user, $actor, $hours * 60);

            $sheet[] = [
                'student_id' => (string) $student->getKey(),
                'name' => $student->name,
                'roll_no' => $enrolment->roll_no,
                'login_name' => (string) $user->username,
                'code' => $setup['code'] ?? null,
                'expires_at' => $setup['expires_at'] ?? now()->addHours($hours)->toIso8601String(),
            ];
        }

        $this->audit->record('student_login.codes_issued', 'security', 'sections', (string) $section->getKey(), meta: [
            'students' => count($sheet),
            'valid_hours' => $hours,
        ]);

        return $sheet;
    }

    /** The student's own account (role Student, a login name, no email or phone). */
    private function accountFor(Student $student): User
    {
        if (is_string($student->user_id)) {
            $user = User::query()->find($student->user_id);

            if ($user instanceof User) {
                return $user;
            }
        }

        $login = self::loginName($student->admission_no);

        $user = AuditRecorder::muted(fn () => User::create([
            'name' => $student->name,
            'username' => $login,
            'email' => null,
            'phone' => null,
            'password' => null,
            'roles' => [Role::Student->value],
            'status' => User::STATUS_INVITED,
        ]));

        $this->audit->record('user.created', 'admin', 'users', (string) $user->getKey(), after: [
            'name' => $user->name, 'username' => $login, 'roles' => $user->roles, 'status' => $user->status, 'how' => 'student_login',
        ]);

        $student->forceFill(['user_id' => (string) $user->getKey(), 'login_name' => $login])->save();

        return $user;
    }

    /** From the admission number: "SPS/2026/0412" becomes "sps20260412". */
    public static function loginName(string $admissionNo): string
    {
        return mb_strtolower(preg_replace('/[^A-Za-z0-9]/', '', $admissionNo) ?? '');
    }
}
