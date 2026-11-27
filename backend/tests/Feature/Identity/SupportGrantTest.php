<?php

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\PlatformAdmin;
use App\Modules\Identity\Models\SupportGrant;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesSchools;
use Tests\Concerns\RefreshMongoDatabase;

uses(RefreshMongoDatabase::class, CreatesSchools::class);

/*
| Item 9: support grants. Read-only by default, a fixed end time, the Chief
| can revoke early, platform agents act only inside an active grant, and the
| school sees each grant and everything done under it.
*/

beforeEach(function () {
    $this->school = $this->activeSchool('HELP-BPL');
    $this->tenant = $this->school['tenant'];
    $this->chief = $this->school['chief'];
    $this->agent = PlatformAdmin::create([
        'name' => 'Asha Support',
        'email' => 'asha@support.campustab.test',
        'password' => 'platform-pass-123456',
        'mfa' => ['enabled' => true],
    ]);
});

function consoleToken(PlatformAdmin $agent): string
{
    return $agent->createToken('console', ['platform'])->plainTextToken;
}

function grant(object $test, array $overrides = []): TestResponse
{
    return $test->actingWithToken($test->chief, steppedUp: true)->postJson('/api/v1/support-grants', array_merge([
        'agent_email' => 'asha@support.campustab.test',
        'scope' => ['users', 'settings'],
        'reason' => 'Help the office set up staff accounts',
        'ends_at' => now()->addHours(4)->toIso8601String(),
    ], $overrides));
}

function openSupport(object $test, PlatformAdmin $agent): TestResponse
{
    app('auth')->forgetGuards();

    return $test->withToken(consoleToken($agent))->postJson('/api/v1/platform/support/sessions', ['school_code' => 'HELP-BPL']);
}

it('denies support access without a grant', function () {
    openSupport($this, $this->agent)
        ->assertForbidden()
        ->assertJsonPath('error.code', 'support_grant_required');
});

it('denies a platform token on school routes unless it is a support session', function () {
    app('auth')->forgetGuards();
    $this->withToken(consoleToken($this->agent))->getJson('/api/v1/users')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'school_account_required');
});

it('lets only the Chief grant access, with step-up MFA', function () {
    $principal = $this->schoolUser($this->tenant, Role::Principal);

    $this->actingWithToken($principal, steppedUp: true)->postJson('/api/v1/support-grants', [])->assertForbidden();
    $this->actingWithToken($this->chief)->postJson('/api/v1/support-grants', [])->assertJsonPath('error.code', 'step_up_required');
});

it('is read-only by default and must end between 15 minutes and 72 hours from now', function () {
    grant($this)->assertCreated()->assertJsonPath('data.read_only', true)->assertJsonPath('data.status', 'active');

    grant($this, ['ends_at' => now()->addMinutes(5)->toIso8601String()])->assertStatus(422)->assertJsonPath('error.code', 'support_grant_window');
    grant($this, ['ends_at' => now()->addHours(73)->toIso8601String()])->assertStatus(422)->assertJsonPath('error.code', 'support_grant_window');
});

it('lets the agent read inside an active read-only grant, and refuses writes', function () {
    grant($this)->assertCreated();
    $token = openSupport($this, $this->agent)->assertCreated()->json('data.token');

    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/users')->assertOk();

    app('auth')->forgetGuards();
    $this->withToken($token)->postJson('/api/v1/users', ['name' => 'New Person', 'email' => 'new@help-bpl.test', 'roles' => ['staff']])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'support_read_only');

    // Areas not granted stay closed.
    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/audit-logs')->assertForbidden();
});

it('never lets support touch support grants or step-up actions, even with a write grant', function () {
    grant($this, ['read_only' => false, 'scope' => ['users', 'settings', 'audit']])->assertCreated();
    $token = openSupport($this, $this->agent)->json('data.token');

    app('auth')->forgetGuards();
    $this->withToken($token)->postJson('/api/v1/support-grants', [])->assertForbidden();

    app('auth')->forgetGuards();
    $this->withToken($token)->putJson('/api/v1/roles/staff', ['permissions' => []])->assertForbidden();
});

it('ends access when the grant expires or the Chief revokes it', function () {
    $id = grant($this)->json('data.id');
    $token = openSupport($this, $this->agent)->json('data.token');

    $this->actingWithToken($this->chief)->postJson("/api/v1/support-grants/{$id}/revoke")
        ->assertOk()
        ->assertJsonPath('data.status', 'revoked');

    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/users')->assertUnauthorized();
    openSupport($this, $this->agent)->assertJsonPath('error.code', 'support_grant_required');

    // A second grant that simply runs out.
    grant($this)->assertCreated();
    $token = openSupport($this, $this->agent)->json('data.token');
    $this->travel(5)->hours();

    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/users')->assertUnauthorized();
});

it('stops a token at once when the grant is revoked, even if the token still exists', function () {
    $id = grant($this)->json('data.id');
    $token = openSupport($this, $this->agent)->json('data.token');

    // Revoked directly in the database: the token itself was not deleted.
    $this->inSchool($this->tenant, fn () => SupportGrant::query()->find($id)->forceFill(['revoked_at' => now()])->save());

    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/users')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'support_grant_inactive');
});

it('logs everything done under a grant with the agent and the grant id, and shows it to the school', function () {
    $id = grant($this)->json('data.id');
    $token = openSupport($this, $this->agent)->json('data.token');

    // A write grant, so a change is logged as the agent.
    $this->inSchool($this->tenant, fn () => SupportGrant::query()->find($id)->forceFill(['read_only' => false])->save());

    app('auth')->forgetGuards();
    $this->withToken($token)->postJson('/api/v1/users', ['name' => 'Ravi Kumar', 'email' => 'ravi@help-bpl.test', 'roles' => ['staff']])
        ->assertForbidden() // level 0 cannot give any role
        ->assertJsonPath('error.code', 'escalation_blocked');

    $this->inSchool($this->tenant, function () use ($id) {
        $opened = AuditLog::query()->where('action', 'support.session_opened')->firstOrFail();
        expect($opened->support_grant_id)->toBe($id)
            ->and($opened->actor['type'])->toBe('support_agent')
            ->and($opened->actor['id'])->toBe((string) $this->agent->getKey());
    });

    $this->actingWithToken($this->chief)->getJson("/api/v1/support-grants/{$id}/activity")
        ->assertOk()
        ->assertJsonPath('data.activity.0.action', 'support.session_opened')
        ->assertJsonPath('data.activity.0.actor.type', 'support_agent');

    $this->actingWithToken($this->chief)->getJson('/api/v1/support-grants')
        ->assertOk()
        ->assertJsonPath('data.0.id', $id)
        ->assertJsonPath('data.0.agent.email', 'asha@support.campustab.test');
});

it('refuses a platform admin without MFA', function () {
    $this->agent->forceFill(['mfa' => ['enabled' => false]])->save();
    grant($this)->assertCreated();

    openSupport($this, $this->agent)->assertForbidden()->assertJsonPath('error.code', 'mfa_enrolment_required');
});
