<?php

use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Http\Middleware\RequireStepUp;
use App\Support\Http\ApiException;
use Illuminate\Http\Request;
use Tests\Concerns\CreatesSchools;
use Tests\Concerns\RefreshMongoDatabase;

uses(RefreshMongoDatabase::class, CreatesSchools::class);

/*
| Step-up MFA: one middleware, a named list of actions in config, valid for
| 10 minutes on the device that entered the code.
*/

beforeEach(function () {
    $this->school = $this->activeSchool('STEP-BPL');
    $this->tenant = $this->school['tenant'];
    $this->chief = $this->school['chief'];
});

it('lists the sensitive actions in config, valid for 10 minutes', function () {
    expect(config('identity.step_up.actions'))->toBe(['export', 'permanent_delete', 'role_edit', 'marks_unlock', 'support_grant', 'mfa_reset'])
        ->and(config('identity.step_up.valid_minutes'))->toBe(10);
});

it('asks for a fresh code before a role edit, then allows it for 10 minutes', function () {
    $token = $this->tokenFor($this->chief);
    $body = ['permissions' => ['staff_attendance.mark:own', 'leave.apply:own', 'notices.view:own', 'notices.view:school']];

    $this->withToken($token)->putJson('/api/v1/roles/staff', $body)
        ->assertForbidden()
        ->assertJsonPath('error.code', 'step_up_required')
        ->assertJsonPath('error.details.action', 'role_edit');

    app('auth')->forgetGuards();
    $this->withToken($token)->postJson('/api/v1/auth/step-up', ['code' => '000000'])->assertStatus(422);

    app('auth')->forgetGuards();
    $this->withToken($token)->postJson('/api/v1/auth/step-up', ['code' => $this->totp($this->chief)])->assertOk();

    app('auth')->forgetGuards();
    $this->withToken($token)->putJson('/api/v1/roles/staff', $body)->assertOk();

    // Another device of the same person has not stepped up.
    $this->actingWithToken($this->chief)->putJson('/api/v1/roles/staff', $body)->assertJsonPath('error.code', 'step_up_required');

    $this->travel(11)->minutes();
    app('auth')->forgetGuards();
    $this->withToken($token)->putJson('/api/v1/roles/staff', $body)->assertJsonPath('error.code', 'step_up_required');
});

it('also asks for it before changing a user\'s roles', function () {
    $teacher = $this->schoolUser($this->tenant, Role::SubjectTeacher, mfaEnabled: false);

    $this->actingWithToken($this->chief)->putJson("/api/v1/users/{$teacher->getKey()}/roles", ['roles' => ['class_teacher']])
        ->assertJsonPath('error.code', 'step_up_required');

    $this->actingWithToken($this->chief, steppedUp: true)->putJson("/api/v1/users/{$teacher->getKey()}/roles", ['roles' => ['class_teacher']])
        ->assertOk()
        ->assertJsonPath('data.roles', ['class_teacher']);
});

it('fails loudly for an action name that is not in the list', function () {
    $request = Request::create('/x', 'POST');

    expect(fn () => app(RequireStepUp::class)->handle($request, fn () => response('ok'), 'delete_everything'))
        ->toThrow(LogicException::class, 'Unknown step-up action');
});

it('asks a user without MFA to set it up first', function () {
    $teacher = $this->schoolUser($this->tenant, Role::ClassTeacher, mfaEnabled: false);
    $request = Request::create('/x', 'POST');
    $request->setUserResolver(fn () => $teacher);

    try {
        app(RequireStepUp::class)->handle($request, fn () => response('ok'), 'export');
        $this->fail('Expected mfa_enrolment_required.');
    } catch (ApiException $e) {
        expect($e->errorCode)->toBe('mfa_enrolment_required');
    }
});
