<?php

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\DeviceSession;
use App\Modules\Identity\Models\User;
use Tests\Concerns\CreatesSchools;
use Tests\Concerns\RefreshMongoDatabase;

uses(RefreshMongoDatabase::class, CreatesSchools::class);

/*
| Item 8: soft delete into the recycle bin, restore, and the Chief's
| permanent purge (30 days, step-up MFA, typed confirmation).
*/

beforeEach(function () {
    $this->school = $this->activeSchool('BIN-BPL');
    $this->tenant = $this->school['tenant'];
    $this->chief = $this->school['chief'];
    $this->principal = $this->schoolUser($this->tenant, Role::Principal);
    $this->teacher = $this->schoolUser($this->tenant, Role::ClassTeacher, mfaEnabled: false);

    $this->actingWithToken($this->principal)->postJson("/api/v1/users/{$this->teacher->getKey()}/deactivate")->assertOk();
    $this->url = "/api/v1/recycle-bin/user/{$this->teacher->getKey()}";
});

it('lists the deactivated account in the recycle bin', function () {
    $this->actingWithToken($this->principal)->getJson('/api/v1/recycle-bin')
        ->assertOk()
        ->assertJsonPath('data.0.type', 'user')
        ->assertJsonPath('data.0.label', $this->teacher->name);
});

it('lets the Principal restore it', function () {
    $this->actingWithToken($this->principal)->postJson($this->url.'/restore')->assertOk();

    $this->inSchool($this->tenant, fn () => expect(User::query()->find($this->teacher->getKey())->status)->toBe('active'));
});

it('never lets the Principal purge', function () {
    $this->travel(31)->days();

    $this->actingWithToken($this->principal, steppedUp: true)->deleteJson($this->url, ['confirm' => $this->teacher->name])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'permission_denied');
});

it('makes the Chief step up, wait 30 days and type the exact name', function () {
    $this->actingWithToken($this->chief)->deleteJson($this->url, ['confirm' => $this->teacher->name])
        ->assertJsonPath('error.code', 'step_up_required')
        ->assertJsonPath('error.details.action', 'permanent_delete');

    $this->actingWithToken($this->chief, steppedUp: true)->deleteJson($this->url, ['confirm' => $this->teacher->name])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'purge_too_early');

    $this->travel(31)->days();

    $this->actingWithToken($this->chief, steppedUp: true)->deleteJson($this->url, ['confirm' => 'delete it'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'confirmation_mismatch');

    $this->actingWithToken($this->chief, steppedUp: true)->deleteJson($this->url, ['confirm' => $this->teacher->name])
        ->assertOk()
        ->assertJsonPath('data.purged', true);

    $this->inSchool($this->tenant, function () {
        expect(User::withTrashed()->find($this->teacher->getKey()))->toBeNull()
            ->and(DeviceSession::query()->where('user_id', (string) $this->teacher->getKey())->count())->toBe(0);

        $entry = AuditLog::query()->where('action', 'purged')->firstOrFail();
        expect($entry->entity_id)->toBe((string) $this->teacher->getKey())
            ->and($entry->before)->toBeNull()
            ->and($entry->after)->toBeNull()
            ->and($entry->actor['id'])->toBe((string) $this->chief->getKey());

        // Older entries about the account stay in the log (see decision 0008, open question).
        expect(AuditLog::query()->where('entity_id', (string) $this->teacher->getKey())->count())->toBeGreaterThan(1);
    });
});
