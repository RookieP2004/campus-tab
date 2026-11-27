<?php

use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use App\Modules\People\Models\Staff;
use App\Modules\People\Models\Student;
use App\Modules\Tenancy\Domain\SchoolConfigService;
use App\Support\Sms\SmsSender;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsSchoolRecords;
use Tests\Concerns\CreatesSchools;
use Tests\Concerns\RefreshMongoDatabase;

uses(RefreshMongoDatabase::class, CreatesSchools::class, BuildsSchoolRecords::class);

beforeEach(function () {
    Mail::fake();
    $this->school = $this->activeSchool('ACCT-BPL');
    $this->tenant = $this->school['tenant'];
    $this->chief = $this->school['chief'];
    $this->sections = $this->makeClass($this->tenant, '7', ['B']);
});

describe('parent login (rule 2)', function () {
    it('makes one Parent account for all of a guardian\'s children, signing in by SMS code', function () {
        $aarav = $this->enrol($this->tenant, $this->sections['B'], 'Aarav Patel', '+919811110001');
        $riya = $this->enrol($this->tenant, $this->sections['B'], 'Riya Patel', '+919811110001');
        $guardian = $this->guardianOf($this->tenant, $aarav);

        $this->actingWithToken($this->chief)->postJson('/api/v1/guardians/'.$guardian->getKey().'/parent-login')
            ->assertCreated()->assertJsonPath('data.parent_login.children', 2);

        $parent = $this->inSchool($this->tenant, fn () => User::query()->where('phone', '+919811110001')->firstOrFail());
        expect($parent->roles)->toBe(['parent'])->and($parent->password)->toBeNull();

        // Sign in with the SMS code, then see both children and nobody else.
        app('auth')->forgetGuards();
        $this->postJson('/api/v1/auth/otp/request', ['school_code' => 'ACCT-BPL', 'phone' => '+919811110001'])->assertStatus(202);
        preg_match('/(\d{6})/', (string) app(SmsSender::class)->lastTo('+919811110001'), $m);
        $token = $this->postJson('/api/v1/auth/otp/verify', ['school_code' => 'ACCT-BPL', 'phone' => '+919811110001', 'code' => $m[1]])->assertOk()->json('data.token');

        $this->enrol($this->tenant, $this->sections['B'], 'Someone Else');
        app('auth')->forgetGuards();
        $names = collect($this->withToken($token)->getJson('/api/v1/students')->assertOk()->json('data'))->pluck('name')->sort()->values()->all();
        expect($names)->toBe(['Aarav Patel', 'Riya Patel']);

        app('auth')->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/students/'.$riya->getKey())->assertOk();
    });

    it('needs the guardian\'s phone', function () {
        $student = $this->enrol($this->tenant, $this->sections['B'], 'Aarav Patel');
        $guardian = $this->guardianOf($this->tenant, $student);
        $this->inSchool($this->tenant, fn () => $guardian->forceFill(['phone' => null])->save());

        $this->actingWithToken($this->chief)->postJson('/api/v1/guardians/'.$guardian->getKey().'/parent-login')
            ->assertStatus(422)->assertJsonPath('error.code', 'phone_required');
    });

    it('adds the Parent role to a teacher who is also a parent, and removes it when turned off', function () {
        $teacher = $this->staffMember($this->tenant, Role::ClassTeacher);
        $student = $this->enrol($this->tenant, $this->sections['B'], 'Child Of Teacher', $teacher['user']->phone);
        $guardian = $this->guardianOf($this->tenant, $student);

        $this->actingWithToken($this->chief)->postJson('/api/v1/guardians/'.$guardian->getKey().'/parent-login')->assertCreated();
        expect($this->freshUser($this->tenant, $teacher['user'])->roles)->toBe(['class_teacher', 'parent']);

        $this->actingWithToken($this->chief)->deleteJson('/api/v1/guardians/'.$guardian->getKey().'/parent-login')->assertOk();
        expect($this->freshUser($this->tenant, $teacher['user'])->roles)->toBe(['class_teacher'])
            ->and($this->freshUser($this->tenant, $teacher['user'])->status)->toBe('active');
    });
});

describe('the Clerk\'s draft for the Principal (rule 3)', function () {
    it('makes no account until the Principal approves the draft', function () {
        $clerk = $this->staffMember($this->tenant, Role::Clerk);
        $principal = $this->staffMember($this->tenant, Role::Principal);

        $id = $this->actingWithToken($clerk['user'])->postJson('/api/v1/staff', [
            'name' => 'Sunita Rao', 'designation' => 'TGT Hindi', 'email' => 'sunita.rao@acct-bpl.test', 'roles' => ['subject_teacher'],
        ])->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.account', null)->json('data.id');

        expect($this->inSchool($this->tenant, fn () => User::query()->where('email', 'sunita.rao@acct-bpl.test')->exists()))->toBeFalse();

        $this->actingWithToken($clerk['user'])->postJson("/api/v1/staff/{$id}/approve")->assertForbidden();

        $this->actingWithToken($principal['user'])->postJson("/api/v1/staff/{$id}/approve")
            ->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.roles', ['subject_teacher'])
            ->assertJsonPath('data.password_setup.channel', 'email');

        $user = $this->inSchool($this->tenant, fn () => User::query()->where('email', 'sunita.rao@acct-bpl.test')->firstOrFail());
        expect($user->status)->toBe('invited')->and($user->password)->toBeNull();
    });

    it('lets the Principal reject a draft, and checks the roles against the approver', function () {
        $clerk = $this->staffMember($this->tenant, Role::Clerk);
        $principal = $this->staffMember($this->tenant, Role::Principal);

        $id = $this->actingWithToken($clerk['user'])->postJson('/api/v1/staff', ['name' => 'Wants Too Much', 'email' => 'x@acct-bpl.test', 'roles' => ['principal']])->json('data.id');
        $this->actingWithToken($principal['user'])->postJson("/api/v1/staff/{$id}/approve")->assertForbidden();
        $this->actingWithToken($principal['user'])->postJson("/api/v1/staff/{$id}/reject", ['reason' => 'Wrong role'])->assertOk();

        expect($this->inSchool($this->tenant, fn () => Staff::query()->find($id)))->toBeNull();
    });

    it('creates staff with the account at once for the Principal', function () {
        $principal = $this->staffMember($this->tenant, Role::Principal);

        $this->actingWithToken($principal['user'])->postJson('/api/v1/staff', ['name' => 'Priya Nair', 'email' => 'priya@acct-bpl.test', 'roles' => ['class_teacher']])
            ->assertCreated()->assertJsonPath('data.status', 'active')->assertJsonPath('data.account.status', 'invited');
    });
});

describe('student sign-in (rule 4)', function () {
    beforeEach(function () {
        $this->teacher = $this->staffMember($this->tenant, Role::ClassTeacher);
        $this->makeClassTeacher($this->tenant, $this->sections['B'], $this->teacher['staff']);
        $this->student = $this->enrol($this->tenant, $this->sections['B'], 'Aarav Patel', null, ['admission_no' => 'SPS/2026/0412']);
        $this->enrol($this->tenant, $this->sections['B'], 'Diya Gupta');
    });

    function turnOnStudentLogin(object $test): void
    {
        $test->inSchool($test->tenant, function () use ($test) {
            $configs = app(SchoolConfigService::class);
            $body = $configs->current()->body;
            $body['sign_in']['methods'][] = 'student_login';
            $configs->save($test->chief, $body, $configs->current()->version);
        });
    }

    it('is off by default: no setup codes', function () {
        $this->actingWithToken($this->teacher['user'])->postJson('/api/v1/sections/'.$this->sections['B']->getKey().'/student-setup-codes')
            ->assertStatus(409)->assertJsonPath('error.code', 'student_login_off');
    });

    it('lets the class teacher print one-time codes valid 48 hours, and the student signs in with a login name', function () {
        turnOnStudentLogin($this);

        $sheet = $this->actingWithToken($this->teacher['user'])->postJson('/api/v1/sections/'.$this->sections['B']->getKey().'/student-setup-codes')
            ->assertCreated()->assertJsonPath('data.valid_hours', 48)->json('data.students');

        $aarav = collect($sheet)->firstWhere('name', 'Aarav Patel');
        expect($sheet)->toHaveCount(2)
            ->and($aarav['login_name'])->toBe('sps20260412')
            ->and($aarav['code'])->toMatch('/^[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/')
            ->and(now()->diffInHours($aarav['expires_at']))->toBeGreaterThan(47);

        // Another class's teacher cannot print them.
        $other = $this->staffMember($this->tenant, Role::ClassTeacher);
        $this->actingWithToken($other['user'])->postJson('/api/v1/sections/'.$this->sections['B']->getKey().'/student-setup-codes')->assertForbidden();

        app('auth')->forgetGuards();
        $this->postJson('/api/v1/auth/password/set', ['school_code' => 'ACCT-BPL', 'token' => $aarav['code'], 'password' => 'my-own-secret-77', 'password_confirmation' => 'my-own-secret-77'])->assertOk();
        $this->postJson('/api/v1/auth/login', ['school_code' => 'ACCT-BPL', 'login' => 'sps20260412', 'password' => 'my-own-secret-77'])->assertOk();
    });

    it('refuses student sign-in when the school turns it off again', function () {
        turnOnStudentLogin($this);
        $code = collect($this->actingWithToken($this->teacher['user'])->postJson('/api/v1/sections/'.$this->sections['B']->getKey().'/student-setup-codes')->json('data.students'))->firstWhere('name', 'Aarav Patel')['code'];
        app('auth')->forgetGuards();
        $this->postJson('/api/v1/auth/password/set', ['school_code' => 'ACCT-BPL', 'token' => $code, 'password' => 'my-own-secret-77', 'password_confirmation' => 'my-own-secret-77'])->assertOk();

        $this->inSchool($this->tenant, function () {
            $configs = app(SchoolConfigService::class);
            $body = $configs->current()->body;
            $body['sign_in']['methods'] = ['password', 'parent_otp'];
            $configs->save($this->chief, $body, $configs->current()->version);
        });

        $this->postJson('/api/v1/auth/login', ['school_code' => 'ACCT-BPL', 'login' => 'sps20260412', 'password' => 'my-own-secret-77'])
            ->assertUnauthorized()->assertJsonPath('error.code', 'sign_in_failed');
        expect($this->inSchool($this->tenant, fn () => Student::query()->findOrFail($this->student->getKey())->login_name))->toBe('sps20260412');
    });
});

describe('academic structure', function () {
    it('lets the Principal set it up and teachers only read it', function () {
        $principal = $this->staffMember($this->tenant, Role::Principal);
        $teacher = $this->staffMember($this->tenant, Role::SubjectTeacher);

        $this->actingWithToken($principal['user'])->postJson('/api/v1/classes', ['grade' => '8', 'name' => 'Class 8', 'sections' => ['A', 'B']])
            ->assertCreated()->assertJsonPath('data.sections.1.label', '8-B');
        $subject = $this->actingWithToken($principal['user'])->postJson('/api/v1/subjects', ['code' => 'sci', 'name' => 'Science'])->assertCreated()->json('data');
        $section = $this->actingWithToken($principal['user'])->getJson('/api/v1/classes')->json('data.1.sections.0');
        $this->actingWithToken($principal['user'])->postJson('/api/v1/teaching-assignments', ['section_id' => $section['id'], 'subject_id' => $subject['id'], 'teacher_id' => (string) $teacher['staff']->getKey()])
            ->assertCreated()->assertJsonPath('data.subject_name', 'Science');

        $this->actingWithToken($teacher['user'])->getJson('/api/v1/classes')->assertOk()->assertJsonCount(2, 'data');
        $this->actingWithToken($teacher['user'])->postJson('/api/v1/subjects', ['code' => 'ART', 'name' => 'Art'])->assertForbidden();
    });

    it('refuses to remove a section that still has students', function () {
        $this->enrol($this->tenant, $this->sections['B'], 'Aarav Patel');
        $this->actingWithToken($this->chief)->deleteJson('/api/v1/sections/'.$this->sections['B']->getKey())->assertStatus(409)->assertJsonPath('error.code', 'section_has_students');
    });
});
