<?php

use App\Modules\Audit\Domain\AuditRecorder;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Domain\Mfa;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use MongoDB\Laravel\Eloquent\SoftDeletes;
use PragmaRX\Google2FA\Google2FA;
use Tests\Concerns\CreatesSchools;
use Tests\Concerns\RefreshMongoDatabase;
use Tests\Concerns\TestData;

uses(RefreshMongoDatabase::class, CreatesSchools::class);

/*
| Item 8: the audit log is append-only, never soft-deleted, holds no
| secrets, and shows each reader only what they may see.
*/

beforeEach(function () {
    Mail::fake();
    $this->school = $this->activeSchool('AUDIT-BPL');
    $this->tenant = $this->school['tenant'];
    $this->chief = $this->school['chief'];
});

describe('append-only', function () {
    beforeEach(function () {
        $this->entry = $this->inSchool($this->tenant, fn () => app(AuditRecorder::class)->record('test.event', 'admin', 'tests', 'x1'));
    });

    it('refuses to change a saved entry', function () {
        $this->inSchool($this->tenant, function () {
            $this->entry->action = 'changed';
            expect(fn () => $this->entry->save())->toThrow(LogicException::class, 'append-only');
        });
    });

    it('refuses to delete an entry', function () {
        $this->inSchool($this->tenant, fn () => expect(fn () => $this->entry->delete())->toThrow(LogicException::class, 'append-only'));
    });

    it('refuses bulk updates, deletes, increments and upserts through the query builder', function () {
        $this->inSchool($this->tenant, function () {
            expect(fn () => AuditLog::query()->where('action', 'test.event')->update(['action' => 'x']))->toThrow(LogicException::class)
                ->and(fn () => AuditLog::query()->where('action', 'test.event')->delete())->toThrow(LogicException::class)
                ->and(fn () => AuditLog::query()->where('action', 'test.event')->forceDelete())->toThrow(LogicException::class)
                ->and(fn () => AuditLog::query()->increment('x'))->toThrow(LogicException::class)
                ->and(fn () => AuditLog::query()->upsert([['action' => 'x']], ['action']))->toThrow(LogicException::class);

            expect(AuditLog::query()->where('action', 'test.event')->count())->toBe(1);
        });
    });

    it('is never soft-deleted', function () {
        expect(class_uses_recursive(AuditLog::class))->not->toContain(SoftDeletes::class);
    });

    it('has no route that changes or deletes an entry', function () {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_contains($r->uri(), 'audit-logs'));

        expect($routes->flatMap(fn ($r) => $r->methods())->unique()->sort()->values()->all())->toBe(['GET', 'HEAD']);
    });
});

describe('no secrets', function () {
    it('never writes passwords, OTPs, TOTP secrets, recovery codes or tokens into before/after', function () {
        // A staff account is created, signs in with MFA, changes password, regenerates codes.
        $teacher = $this->schoolUser($this->tenant, Role::ClassTeacher, mfaEnabled: false);
        $secret = null;
        $codes = [];

        $this->inSchool($this->tenant, function () use ($teacher, &$secret, &$codes) {
            $mfa = app(Mfa::class);
            $user = User::query()->find($teacher->getKey());
            $secret = $mfa->startEnrolment($user, $this->tenant)['secret'];
            $codes = $mfa->confirmEnrolment($user, app(Google2FA::class)->getCurrentOtp($secret));
        });

        $token = $this->tokenFor($teacher);
        $this->withToken($token)->putJson('/api/v1/me/password', [
            'current_password' => TestData::PASSWORD,
            'password' => 'a brand new long phrase 77',
            'password_confirmation' => 'a brand new long phrase 77',
        ])->assertOk();

        $all = DB::connection('mongodb')->getCollection('audit_logs')
            ->find(['tenant_id' => $this->tenant->id], ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']])
            ->toArray();
        $dump = json_encode($all);

        expect($all)->not->toBeEmpty()
            ->and($dump)->not->toContain(TestData::PASSWORD)
            ->and($dump)->not->toContain('a brand new long phrase 77')
            ->and($dump)->not->toContain($secret)
            ->and($dump)->not->toContain(explode('|', $token)[1])
            ->and($dump)->not->toContain('"password":')
            ->and($dump)->not->toContain('mfa_secret":');

        foreach ($codes as $code) {
            expect($dump)->not->toContain($code);
        }

        // The change is still visible: the field name is listed as redacted.
        $changed = collect($all)->first(fn ($e) => $e['action'] === 'updated' && in_array('after.password', $e['redacted'] ?? [], true));
        expect($changed)->not->toBeNull();
    });
});

describe('who sees what', function () {
    beforeEach(function () {
        $this->principal = $this->schoolUser($this->tenant, Role::Principal);
        $this->clerk = $this->schoolUser($this->tenant, Role::Clerk);

        $this->inSchool($this->tenant, function () {
            $recorder = app(AuditRecorder::class);
            $recorder->record('academic.thing', 'academic', 'papers', 'p1', actor: $this->chief);
            $recorder->record('admin.thing', 'admin', 'roles', 'r1', actor: $this->chief);
            $recorder->record('clerk.thing', 'admin', 'students', 's1', actor: $this->clerk);
            $recorder->record('principal.thing', 'admin', 'users', 'u1', actor: $this->principal);
        });
    });

    it('shows the Chief everything', function () {
        expect(actions($this, $this->chief))->toContain('academic.thing', 'admin.thing', 'clerk.thing', 'principal.thing');
    });

    it('shows the Principal academic entries and their own actions', function () {
        $seen = actions($this, $this->principal);

        expect($seen)->toContain('academic.thing', 'principal.thing')
            ->not->toContain('admin.thing')
            ->not->toContain('clerk.thing');
    });

    it('shows a Clerk only their own actions', function () {
        $seen = actions($this, $this->clerk);

        expect($seen)->toContain('clerk.thing')->not->toContain('academic.thing')->not->toContain('admin.thing');
    });

    it('shows a teacher nothing', function () {
        $teacher = $this->schoolUser($this->tenant, Role::ClassTeacher, mfaEnabled: false);

        $this->actingWithToken($teacher)->getJson('/api/v1/audit-logs')->assertForbidden();
    });

    it('marks a Principal-editable config change as academic', function () {
        $body = $this->configBody($this->tenant);
        $body['policies']['attendance']['edit_window_hours'] = 6;

        $this->actingWithToken($this->principal)->withHeader('If-Match', '"v1"')
            ->putJson('/api/v1/config', ['body' => $body])->assertOk();

        $this->inSchool($this->tenant, function () {
            $entry = AuditLog::query()->where('entity', 'school_configs')->where('action', 'created')->orderBy('_id', 'desc')->first();
            expect($entry->category)->toBe('academic')
                ->and($entry->after['diff'][0]['path'])->toBe('policies.attendance.edit_window_hours')
                ->and($entry->after)->not->toHaveKey('body');
        });
    });
});

it('records before and after values, actor, IP and device for a change', function () {
    $teacher = $this->schoolUser($this->tenant, Role::Staff, mfaEnabled: false);

    $this->actingWithToken($teacher)->withHeader('User-Agent', 'CampusTab-Test/1.0')
        ->patchJson('/api/v1/me/preferences', ['theme' => 'dark'])->assertOk();

    $this->inSchool($this->tenant, function () use ($teacher) {
        $entry = AuditLog::query()->where('entity', 'users')->where('action', 'updated')->where('entity_id', (string) $teacher->getKey())->firstOrFail();

        expect($entry->before)->toBe(['theme' => 'system'])
            ->and($entry->after)->toBe(['theme' => 'dark'])
            ->and($entry->actor['id'])->toBe((string) $teacher->getKey())
            ->and($entry->ip)->toBe('127.0.0.1')
            ->and($entry->device['user_agent'])->toBe('CampusTab-Test/1.0')
            ->and($entry->device['session_id'])->not->toBeNull();
    });
});

function actions(object $test, User $user): array
{
    return collect($test->actingWithToken($user)->getJson('/api/v1/audit-logs')->assertOk()->json('data'))->pluck('action')->all();
}
