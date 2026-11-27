<?php

use App\Modules\Audit\Domain\AuditRecorder;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesSchools;
use Tests\Concerns\RefreshMongoDatabase;
use Tests\Concerns\TestData;

uses(RefreshMongoDatabase::class, CreatesSchools::class);

/*
| The phase tests for part B's endpoints: cross-tenant reads and writes are
| blocked, and an expired licence is read-only (account safety still works).
*/

beforeEach(function () {
    Mail::fake();
});

describe('cross-tenant', function () {
    beforeEach(function () {
        $this->a = $this->activeSchool('ALPHA-BPL');
        $this->b = $this->activeSchool('BETA-IDR');
        $this->principalA = $this->schoolUser($this->a['tenant'], Role::Principal);
        $this->teacherB = $this->schoolUser($this->b['tenant'], Role::ClassTeacher, mfaEnabled: false);

        $this->inSchool($this->b['tenant'], fn () => app(AuditRecorder::class)->record('beta.secret', 'academic', 'papers', 'p9'));
    });

    it('lists only the signed-in school\'s users', function () {
        $ids = collect($this->actingWithToken($this->principalA)->getJson('/api/v1/users')->assertOk()->json('data'))->pluck('id');

        expect($ids)->not->toContain((string) $this->teacherB->getKey());
    });

    it('cannot act on another school\'s user by id', function (string $method, string $path, array $body) {
        $url = str_replace('{id}', (string) $this->teacherB->getKey(), $path);

        $this->actingWithToken($this->principalA, steppedUp: true)->json($method, $url, $body)->assertNotFound();

        $this->inSchool($this->b['tenant'], function () {
            $teacher = User::query()->find($this->teacherB->getKey());
            expect($teacher->roles)->toBe(['class_teacher'])->and($teacher->status)->toBe('active');
        });
    })->with([
        'unlock' => ['POST', '/api/v1/users/{id}/unlock', []],
        'deactivate' => ['POST', '/api/v1/users/{id}/deactivate', []],
        'roles' => ['PUT', '/api/v1/users/{id}/roles', ['roles' => ['staff']]],
        'password link' => ['POST', '/api/v1/users/{id}/password-link', []],
        'restore' => ['POST', '/api/v1/recycle-bin/user/{id}/restore', []],
    ]);

    it('shows only the school\'s own audit entries and support grants', function () {
        $actions = collect($this->actingWithToken($this->a['chief'])->getJson('/api/v1/audit-logs')->assertOk()->json('data'))->pluck('action');
        expect($actions)->not->toContain('beta.secret');

        $this->actingWithToken($this->a['chief'])->getJson('/api/v1/support-grants')->assertOk()->assertJsonCount(0, 'data');
    });

    it('signs in only inside the school whose code was given', function () {
        $this->postJson('/api/v1/auth/login', ['school_code' => 'ALPHA-BPL', 'login' => $this->teacherB->email, 'password' => TestData::PASSWORD])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'sign_in_failed');
    });
});

describe('expired licence', function () {
    beforeEach(function () {
        $this->school = $this->activeSchool('LAPSED-BPL', [
            'valid_from' => now('Asia/Kolkata')->subYears(2)->toDateString(),
            'valid_to' => now('Asia/Kolkata')->subDays(30)->toDateString(),
        ]);
        $this->principal = $this->schoolUser($this->school['tenant'], Role::Principal);
    });

    it('refuses writes to school data and allows reads', function () {
        $this->actingWithToken($this->principal)->getJson('/api/v1/users')->assertOk()->assertHeader('X-Licence-State', 'read_only');

        $this->actingWithToken($this->principal)->postJson('/api/v1/users', ['name' => 'New Person', 'email' => 'new@lapsed-bpl.test', 'roles' => ['staff']])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'licence_read_only');
    });

    it('still lets people sign in and look after their own account and others\' access', function () {
        $teacher = $this->schoolUser($this->school['tenant'], Role::ClassTeacher, mfaEnabled: false);

        $this->postJson('/api/v1/auth/login', ['school_code' => 'LAPSED-BPL', 'login' => $teacher->email, 'password' => TestData::PASSWORD])->assertOk();
        $this->actingWithToken($teacher)->patchJson('/api/v1/me/preferences', ['theme' => 'dark'])->assertOk();
        $this->actingWithToken($this->principal)->postJson("/api/v1/users/{$teacher->getKey()}/unlock")->assertOk();
    });
});
