<?php

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Domain\AccessControl;
use App\Modules\Identity\Domain\PermissionCatalogue;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Domain\Scopes\AccessSubject;
use App\Modules\Identity\Models\SchoolRole;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesSchools;
use Tests\Concerns\RefreshMongoDatabase;

uses(RefreshMongoDatabase::class, CreatesSchools::class);

/*
| Item 7: permissions as module.action:scope, the ten seeded roles, scope
| resolvers, no-escalation and last-Chief guards. Fee Collector is a duty.
*/

beforeEach(function () {
    Mail::fake();
    $this->school = $this->activeSchool('RBAC-BPL');
    $this->tenant = $this->school['tenant'];
    $this->chief = $this->school['chief'];
    $this->principal = $this->schoolUser($this->tenant, Role::Principal);
});

function subject(array $facts): AccessSubject
{
    return new class($facts) implements AccessSubject
    {
        public function __construct(private array $facts) {}

        public function accessFacts(): array
        {
            return $this->facts;
        }
    };
}

describe('the seeded roles', function () {
    it('seeds the ten system roles into every new school', function () {
        $roles = $this->inSchool($this->tenant, fn () => SchoolRole::query()->get()->keyBy('name'));

        expect($roles->keys()->sort()->values()->all())->toBe([
            'attendance_officer', 'chief', 'class_teacher', 'clerk', 'fee_collector', 'parent', 'principal', 'staff', 'student', 'subject_teacher', 'vice_principal',
        ])
            ->and($roles['chief']->level)->toBe(90)
            ->and($roles['fee_collector']->level)->toBeNull()
            ->and($roles['fee_collector']->duty)->toBe('fee_collector')
            ->and($roles['attendance_officer']->level)->toBeNull()
            ->and($roles['attendance_officer']->duty)->toBe('attendance_officer');
    });

    it('uses only permission strings the catalogue knows', function () {
        foreach (config('permissions.roles') as $name => $role) {
            foreach ($role['permissions'] as $permission) {
                expect(PermissionCatalogue::isValid($permission))->toBeTrue("{$name}: {$permission}");
            }
        }
    });

    it('has no permission that lets anyone change a locked mark directly', function () {
        $abilities = array_keys(PermissionCatalogue::abilities());

        expect($abilities)->not->toContain('marks.edit_locked')
            ->and(array_filter($abilities, fn ($a) => str_contains($a, 'locked')))->toBe([])
            ->and(config('permissions.roles.clerk.permissions'))->not->toContain('marks.correction_approve:school');
    });

    it('makes Fee Collector a duty that adds fee rights and nothing else', function () {
        $collector = $this->schoolUser($this->tenant, Role::Staff, mfaEnabled: false, extraRoles: ['fee_collector']);
        $staff = $this->schoolUser($this->tenant, Role::Staff, mfaEnabled: false);

        $this->inSchool($this->tenant, function () use ($collector, $staff) {
            $added = array_values(array_diff($collector->permissions(), $staff->permissions()));
            sort($added);

            expect($added)->toBe(['fees.collect:school', 'fees.dues_view:school', 'fees.receipt_cancel_request:school', 'fees.reports:own'])
                ->and($collector->level())->toBe(30);
        });
    });

    it('refuses any non-fee permission on the Fee Collector duty', function () {
        $this->actingWithToken($this->chief, steppedUp: true)
            ->putJson('/api/v1/roles/fee_collector', ['permissions' => ['fees.collect:school', 'users.view:school']])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'duty_limited');
    });

    it('makes Attendance Officer a duty that adds staff-attendance entry rights and nothing else', function () {
        $officer = $this->schoolUser($this->tenant, Role::Staff, mfaEnabled: false, extraRoles: ['attendance_officer']);
        $staff = $this->schoolUser($this->tenant, Role::Staff, mfaEnabled: false);

        $this->inSchool($this->tenant, function () use ($officer, $staff) {
            $added = array_values(array_diff($officer->permissions(), $staff->permissions()));
            sort($added);

            expect($added)->toBe(['staff_attendance.enter:school', 'staff_attendance.view:school'])
                ->and($officer->level())->toBe(30);
        });
    });

    it('lets the Principal give the Attendance Officer duty', function () {
        $clerk = $this->schoolUser($this->tenant, Role::Clerk);

        $this->actingWithToken($this->principal, steppedUp: true)
            ->putJson("/api/v1/users/{$clerk->getKey()}/roles", ['roles' => ['clerk', 'attendance_officer']])
            ->assertOk()
            ->assertJsonPath('data.roles', ['clerk', 'attendance_officer']);
    });

    it('refuses anything but staff-attendance entry on the Attendance Officer duty, even other attendance rights', function (string $extra) {
        $this->actingWithToken($this->chief, steppedUp: true)
            ->putJson('/api/v1/roles/attendance_officer', ['permissions' => ['staff_attendance.enter:school', $extra]])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'duty_limited')
            ->assertJsonPath('error.details.permissions', [$extra]);
    })->with(['staff_attendance.manage:school', 'attendance.mark:school', 'fees.collect:school', 'users.view:school']);
});

describe('scope resolvers', function () {
    it('covers wider scopes over narrower ones, and child only by itself or school', function () {
        expect(PermissionCatalogue::scopeCovers('school', 'own'))->toBeTrue()
            ->and(PermissionCatalogue::scopeCovers('wing', 'class'))->toBeTrue()
            ->and(PermissionCatalogue::scopeCovers('class', 'subject'))->toBeTrue()
            ->and(PermissionCatalogue::scopeCovers('subject', 'class'))->toBeFalse()
            ->and(PermissionCatalogue::scopeCovers('school', 'child'))->toBeTrue()
            ->and(PermissionCatalogue::scopeCovers('wing', 'child'))->toBeFalse()
            ->and(PermissionCatalogue::scopeCovers('child', 'own'))->toBeFalse();
    });

    // The own, child, subject, class and wing resolvers are tested from real
    // records (guardians, sections, teaching assignments, wings) in
    // tests/Feature/People/ScopeResolverTest.php.
    it('gives a school-wide permission every record, and a scoped one no record without facts', function () {
        $access = app(AccessControl::class);
        $classTeacher = $this->schoolUser($this->tenant, Role::ClassTeacher, mfaEnabled: false);

        $this->inSchool($this->tenant, function () use ($access, $classTeacher) {
            $outside = subject(['section_id' => 'sec-9b', 'subject_key' => 'sec-9b:maths', 'wing' => 'senior', 'student_ids' => ['stu-2'], 'owner_ids' => ['someone-else']]);

            expect($access->allows($classTeacher, 'students.view', $outside))->toBeFalse()
                ->and($access->allows($classTeacher, 'students.view'))->toBeFalse()
                ->and($access->allows($this->principal, 'students.view', $outside))->toBeTrue()
                ->and($access->allows($this->principal, 'students.view'))->toBeTrue();
        });
    });
});

describe('no escalation', function () {
    it('lets the Principal create a Clerk, who gets a set-password link instead of a typed password', function () {
        $response = $this->actingWithToken($this->principal)->postJson('/api/v1/users', [
            'name' => 'Meera Joshi', 'email' => 'meera.clerk@rbac-bpl.test', 'roles' => ['clerk'],
        ])->assertCreated()
            ->assertJsonPath('data.has_password', false)
            ->assertJsonPath('data.password_setup.channel', 'email');

        expect($response->json('data.password_setup.to'))->not->toBe('meera.clerk@rbac-bpl.test');
    });

    it('refuses a second account with the same email or phone in the school', function () {
        $clerk = $this->schoolUser($this->tenant, Role::Clerk);

        $this->actingWithToken($this->principal)->postJson('/api/v1/users', ['name' => 'Copy', 'email' => strtoupper($clerk->email), 'roles' => ['staff']])
            ->assertStatus(422)->assertJsonPath('error.code', 'user_exists');
        $this->actingWithToken($this->principal)->postJson('/api/v1/users', ['name' => 'Copy', 'phone' => $clerk->phone, 'roles' => ['staff']])
            ->assertStatus(422)->assertJsonPath('error.code', 'user_exists');
    });

    it('blocks the Principal from creating a Principal or a Chief', function (string $role) {
        $this->actingWithToken($this->principal)->postJson('/api/v1/users', [
            'name' => 'Too High', 'email' => "too.high.{$role}@rbac-bpl.test", 'roles' => [$role],
        ])->assertForbidden()->assertJsonPath('error.code', 'escalation_blocked');
    })->with(['principal', 'chief']);

    it('lets the Principal give the Fee Collector duty', function () {
        $staff = $this->schoolUser($this->tenant, Role::Staff, mfaEnabled: false);

        $this->actingWithToken($this->principal, steppedUp: true)
            ->putJson("/api/v1/users/{$staff->getKey()}/roles", ['roles' => ['staff', 'fee_collector']])
            ->assertOk()
            ->assertJsonPath('data.roles', ['staff', 'fee_collector']);
    });

    it('blocks the Principal from promoting someone to Principal or acting on a Chief', function () {
        $clerk = $this->schoolUser($this->tenant, Role::Clerk);

        $this->actingWithToken($this->principal, steppedUp: true)
            ->putJson("/api/v1/users/{$clerk->getKey()}/roles", ['roles' => ['principal']])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'escalation_blocked');

        $this->actingWithToken($this->principal, steppedUp: true)
            ->putJson("/api/v1/users/{$this->chief->getKey()}/roles", ['roles' => ['chief', 'principal']])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'level_too_low');
    });

    it('blocks a teacher from managing users at all', function () {
        $teacher = $this->schoolUser($this->tenant, Role::ClassTeacher, mfaEnabled: false);

        $this->actingWithToken($teacher)->getJson('/api/v1/users')->assertForbidden()->assertJsonPath('error.code', 'permission_denied');
        $this->actingWithToken($teacher)->postJson('/api/v1/users', ['name' => 'X Y', 'email' => 'x@rbac-bpl.test', 'roles' => ['staff']])
            ->assertForbidden();
    });

    it('refuses permissions the editor does not hold, even with roles.edit', function () {
        // The Chief lets the Principal edit roles (the Principal still lacks records.purge).
        $this->inSchool($this->tenant, function () {
            $role = SchoolRole::query()->where('name', 'principal')->firstOrFail();
            $role->forceFill(['permissions' => array_merge($role->permissions, ['roles.edit:school'])])->save();
        });

        $clerkPermissions = config('permissions.roles.clerk.permissions');

        $this->actingWithToken($this->principal, steppedUp: true)
            ->putJson('/api/v1/roles/clerk', ['permissions' => array_merge($clerkPermissions, ['records.purge:school'])])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'escalation_blocked')
            ->assertJsonPath('error.details.permissions', ['records.purge:school']);

        $this->actingWithToken($this->principal, steppedUp: true)
            ->putJson('/api/v1/roles/vice_principal', ['permissions' => ['students.view:wing']])
            ->assertOk();

        $this->actingWithToken($this->principal, steppedUp: true)
            ->putJson('/api/v1/roles/principal', ['permissions' => []])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'escalation_blocked');
    });

    it('refuses unknown permission strings', function () {
        $this->actingWithToken($this->chief, steppedUp: true)
            ->putJson('/api/v1/roles/clerk', ['permissions' => ['marks.edit_locked:school']])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'permission_unknown');
    });

    it('keeps the Chief role fixed', function () {
        $this->actingWithToken($this->chief, steppedUp: true)
            ->putJson('/api/v1/roles/chief', ['permissions' => ['config.view:school']])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'role_locked');
    });

    it('only allows custom roles below the creator\'s level', function () {
        $this->actingWithToken($this->chief, steppedUp: true)
            ->postJson('/api/v1/roles', ['name' => 'exam_cell', 'label' => 'Exam Cell', 'level' => 55, 'permissions' => ['exams.schedule:school']])
            ->assertCreated();

        $this->actingWithToken($this->chief, steppedUp: true)
            ->postJson('/api/v1/roles', ['name' => 'super_chief', 'label' => 'Super', 'level' => 95, 'permissions' => []])
            ->assertStatus(422);
    });
});

describe('the last Chief', function () {
    it('cannot be deactivated', function () {
        $this->actingWithToken($this->chief)->postJson("/api/v1/users/{$this->chief->getKey()}/deactivate")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'last_chief');
    });

    it('cannot lose the Chief role', function () {
        $this->actingWithToken($this->chief, steppedUp: true)
            ->putJson("/api/v1/users/{$this->chief->getKey()}/roles", ['roles' => ['principal']])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'last_chief');
    });

    it('can be demoted once there is another Chief, and both changes are security events', function () {
        $second = $this->schoolUser($this->tenant, Role::Principal);

        $this->actingWithToken($this->chief, steppedUp: true)
            ->putJson("/api/v1/users/{$second->getKey()}/roles", ['roles' => ['chief']])
            ->assertOk();

        $this->actingWithToken($second, steppedUp: true)
            ->putJson("/api/v1/users/{$this->chief->getKey()}/roles", ['roles' => ['principal']])
            ->assertOk();

        $this->inSchool($this->tenant, function () use ($second) {
            expect(AuditLog::query()->where('action', 'chief.added')->where('entity_id', (string) $second->getKey())->where('category', 'security')->exists())->toBeTrue()
                ->and(AuditLog::query()->where('action', 'chief.removed')->where('entity_id', (string) $this->chief->getKey())->exists())->toBeTrue();
        });

        $this->actingWithToken($second)->postJson("/api/v1/users/{$second->getKey()}/deactivate")
            ->assertJsonPath('error.code', 'last_chief');
    });
});

it('deactivates staff into the recycle bin and signs out their devices ("delete staff")', function () {
    $teacher = $this->schoolUser($this->tenant, Role::ClassTeacher, mfaEnabled: false);
    $token = $this->tokenFor($teacher);

    $this->actingWithToken($this->principal)->postJson("/api/v1/users/{$teacher->getKey()}/deactivate")
        ->assertOk()
        ->assertJsonPath('data.in_recycle_bin', true);

    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();

    $this->inSchool($this->tenant, function () use ($teacher) {
        $stored = User::withTrashed()->find($teacher->getKey());
        expect($stored->trashed())->toBeTrue()->and($stored->status)->toBe('inactive');
    });
});

it('returns the user\'s permissions in GET /me so the app can hide what they cannot do', function () {
    $this->actingWithToken($this->principal)->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.level', 80)
        ->assertJsonFragment(['config.edit_policies:school']);
});
