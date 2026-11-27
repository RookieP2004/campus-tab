<?php

use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use Tests\Concerns\CreatesSchools;
use Tests\Concerns\RefreshMongoDatabase;
use Tests\Concerns\TestData;

uses(RefreshMongoDatabase::class, CreatesSchools::class);

beforeEach(function () {
    $this->school = $this->activeSchool('IDLE-BPL');
    $this->tenant = $this->school['tenant'];
});

/*
| Idle sign-out counts only real use. Requests the screens send on their
| own (refetches, polling, the notification socket) carry
| X-Background-Request: 1: they are checked, but never extend the session.
*/
describe('background requests', function () {
    it('does not let background requests keep an idle session alive', function () {
        $token = $this->tokenFor($this->school['chief']);

        $this->travel(10)->minutes();
        app('auth')->forgetGuards();
        $this->withToken($token)->withHeader('X-Background-Request', '1')->getJson('/api/v1/me')->assertOk();

        $this->travel(6)->minutes();
        app('auth')->forgetGuards();
        $this->withToken($token)->withHeader('X-Background-Request', '1')->getJson('/api/v1/me')
            ->assertUnauthorized()->assertJsonPath('error.code', 'session_expired');
    });

    it('counts a request without the header as use', function () {
        $token = $this->tokenFor($this->school['chief']);

        $this->travel(10)->minutes();
        app('auth')->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me')->assertOk();

        $this->travel(6)->minutes();
        app('auth')->forgetGuards();
        $this->withToken($token)->withHeader('X-Background-Request', '1')->getJson('/api/v1/me')->assertOk();
    });

    it('treats only the value 1 as background', function () {
        $token = $this->tokenFor($this->school['chief']);

        $this->travel(10)->minutes();
        app('auth')->forgetGuards();
        $this->withToken($token)->withHeader('X-Background-Request', 'yes')->getJson('/api/v1/me')->assertOk();

        $this->travel(6)->minutes();
        app('auth')->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me')->assertOk();
    });
});

/*
| Part B: an account with no password (an invited user who has not used
| the link yet, or a parent who signs in by SMS code) can never sign in by
| password, whatever is typed.
*/
describe('accounts without a password', function () {
    it('never signs in an invited user by password', function () {
        $this->actingWithToken($this->school['chief'])->postJson('/api/v1/users', [
            'name' => 'Made Up Teacher', 'email' => 'invited.teacher@idle-bpl.test', 'roles' => ['class_teacher'],
        ])->assertCreated()->assertJsonPath('data.status', 'invited');

        app('auth')->forgetGuards();

        foreach (['x', 'null', 'password', '0', 'correct-horse-42-battery', str_repeat('a', 199)] as $typed) {
            $this->postJson('/api/v1/auth/login', ['school_code' => 'IDLE-BPL', 'login' => 'invited.teacher@idle-bpl.test', 'password' => $typed])
                ->assertUnauthorized()->assertJsonPath('error.code', 'sign_in_failed');
        }
    });

    it('never signs in a parent account by password, even with an empty or null hash stored', function () {
        $parent = $this->inSchool($this->tenant, fn () => User::create([
            'name' => 'Made Up Parent', 'phone' => '+919811111111', 'roles' => [Role::Parent->value], 'status' => 'active',
        ]));

        foreach ([null, ''] as $stored) {
            $this->inSchool($this->tenant, fn () => User::query()->whereKey($parent->getKey())->update(['password' => $stored]));

            foreach (['x', 'null', '0', TestData::PASSWORD] as $typed) {
                $this->postJson('/api/v1/auth/login', ['school_code' => 'IDLE-BPL', 'login' => '+919811111111', 'password' => $typed])
                    ->assertUnauthorized()->assertJsonPath('error.code', 'sign_in_failed');
            }
        }
    });
});
