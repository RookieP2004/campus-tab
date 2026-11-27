<?php

use App\Modules\Identity\Domain\Role;
use App\Modules\Tenancy\Domain\ConfigDiff;
use App\Modules\Tenancy\Domain\ConfigSchema;
use App\Modules\Tenancy\Domain\ConfigSections;
use App\Modules\Tenancy\Models\SchoolConfig;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Arr;
use Tests\Concerns\CreatesSchools;
use Tests\Concerns\RefreshMongoDatabase;

uses(RefreshMongoDatabase::class, CreatesSchools::class);

/*
| Item 4: versioned school config with JSON-schema validation, diff and
| rollback, plus edit rights by section (SchoolConfigPolicy).
*/

beforeEach(function () {
    $this->school = $this->activeSchool('SUNRISE-BPL');
    $this->chief = $this->school['chief'];
    $this->principal = $this->schoolUser($this->school['tenant'], Role::Principal);
});

/** The current body with one setting changed (dot path). */
function changed(object $test, string $path, mixed $value): array
{
    $body = $test->configBody($test->school['tenant']);
    Arr::set($body, $path, $value);

    return $body;
}

function versionCount(object $test): int
{
    return app(TenantContext::class)->run($test->school['tenant']->id, fn () => SchoolConfig::query()->count());
}

it('returns the current version with an ETag', function () {
    $this->actingWithToken($this->chief)->getJson('/api/v1/config')
        ->assertOk()
        ->assertHeader('ETag', '"v1"')
        ->assertJsonPath('data.version', 1)
        ->assertJsonPath('data.body.school.name', $this->school['tenant']->name)
        ->assertJsonPath('data.changed_by.level', 90);
});

it('saves a change as a new version with who, why and a diff', function () {
    $this->actingWithToken($this->chief)
        ->putJson('/api/v1/config', ['body' => changed($this, 'branding.accent', '#1D4ED8'), 'reason' => 'New school colours'], ['If-Match' => '"v1"'])
        ->assertOk()
        ->assertHeader('ETag', '"v2"')
        ->assertJsonPath('data.version', 2)
        ->assertJsonPath('data.body.branding.accent', '#1D4ED8');

    $this->actingWithToken($this->chief)->getJson('/api/v1/config/versions')
        ->assertOk()
        ->assertJsonPath('data.0.version', 2)
        ->assertJsonPath('data.0.reason', 'New school colours')
        ->assertJsonPath('data.0.changed_by.id', (string) $this->chief->getKey())
        ->assertJsonPath('data.0.diff', [['path' => 'branding.accent', 'from' => '#0F766E', 'to' => '#1D4ED8']])
        ->assertJsonPath('data.1.version', 1);

    $this->actingWithToken($this->chief)->getJson('/api/v1/config/versions/1')
        ->assertOk()
        ->assertJsonPath('data.body.branding.accent', '#0F766E');
});

it('does not write a version when nothing changed', function () {
    $this->actingWithToken($this->chief)
        ->putJson('/api/v1/config', ['body' => $this->configBody($this->school['tenant'])], ['If-Match' => '"v1"'])
        ->assertOk()
        ->assertJsonPath('data.version', 1);

    expect(versionCount($this))->toBe(1);
});

it('needs If-Match, and refuses a save based on an old version', function () {
    $body = changed($this, 'branding.accent', '#1D4ED8');

    $this->actingWithToken($this->chief)->putJson('/api/v1/config', ['body' => $body])
        ->assertStatus(428)
        ->assertJsonPath('error.code', 'precondition_required');

    $this->actingWithToken($this->chief)->putJson('/api/v1/config', ['body' => $body], ['If-Match' => '"v1"'])->assertOk();

    $this->actingWithToken($this->principal)
        ->putJson('/api/v1/config', ['body' => changed($this, 'academic.year_start_month', 6)], ['If-Match' => '"v1"'])
        ->assertStatus(412)
        ->assertJsonPath('error.code', 'config_conflict')
        ->assertJsonPath('error.details.current_version', 2);
});

it('validates the body against the JSON schema', function (string $path, mixed $value) {
    $this->actingWithToken($this->chief)
        ->putJson('/api/v1/config', ['body' => changed($this, $path, $value)], ['If-Match' => '"v1"'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'config_invalid');

    expect(versionCount($this))->toBe(1);
})->with([
    'accent is not a colour' => ['branding.accent', 'teal'],
    'unknown language' => ['locale.default_language', 'xx'],
    'month out of range' => ['academic.year_start_month', 13],
    'unknown board' => ['school.board', 'MADE-UP'],
    'unknown setting' => ['policies.attendance.surprise', true],
    'unknown section' => ['secret_section', ['a' => 1]],
]);

it('has no setting that lets a clerk change locked marks: that rule is fixed in code', function () {
    $this->actingWithToken($this->chief)
        ->putJson('/api/v1/config', ['body' => changed($this, 'policies.marks.clerk_can_edit_locked', true)], ['If-Match' => '"v1"'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'config_invalid');

    $schema = json_decode(file_get_contents(ConfigSchema::path()), true);
    expect(array_keys($schema['properties']['policies']['properties']['marks']['properties']))->toBe(['auto_publish_class_tests']);

    $propertyNames = [];
    $collect = function (array $node) use (&$collect, &$propertyNames) {
        foreach ($node['properties'] ?? [] as $name => $child) {
            $propertyNames[] = $name;
            $collect($child);
        }
    };
    $collect($schema);

    expect(array_filter($propertyNames, fn ($n) => str_contains($n, 'lock')))->toBe([]);
});

it('refuses to switch on a module the licence does not include', function () {
    $school = $this->activeSchool('NOFEES-BPL', ['modules' => ['attendance', 'leave']]);
    $body = $this->configBody($school['tenant']);
    $body['modules']['fees'] = true;

    $this->actingWithToken($school['chief'])
        ->putJson('/api/v1/config', ['body' => $body], ['If-Match' => '"v1"'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'module_not_licensed')
        ->assertJsonPath('error.details.modules', ['fees']);
});

describe('rollback', function () {
    it('writes a new version with the old body, never rewriting history', function () {
        $this->actingWithToken($this->chief)->putJson('/api/v1/config', ['body' => changed($this, 'branding.accent', '#1D4ED8')], ['If-Match' => '"v1"'])->assertOk();

        $this->actingWithToken($this->chief)->postJson('/api/v1/config/versions/1/rollback', ['reason' => 'Back to teal'], ['If-Match' => '"v2"'])
            ->assertOk()
            ->assertJsonPath('data.version', 3)
            ->assertJsonPath('data.rolled_back_from', 1)
            ->assertJsonPath('data.body.branding.accent', '#0F766E');

        expect(versionCount($this))->toBe(3);
        $this->actingWithToken($this->chief)->getJson('/api/v1/config/versions/2')->assertJsonPath('data.body.branding.accent', '#1D4ED8');
    });

    it('applies the same section rules to the Principal', function () {
        $this->actingWithToken($this->chief)->putJson('/api/v1/config', ['body' => changed($this, 'branding.accent', '#1D4ED8')], ['If-Match' => '"v1"'])->assertOk();
        $this->actingWithToken($this->principal)->putJson('/api/v1/config', ['body' => changed($this, 'academic.terms', ['Term 1', 'Term 2', 'Term 3'])], ['If-Match' => '"v2"'])->assertOk();

        // Rolling back to v2 only undoes the Principal's academic change: allowed.
        $this->actingWithToken($this->principal)->postJson('/api/v1/config/versions/2/rollback', [], ['If-Match' => '"v3"'])->assertOk()->assertJsonPath('data.version', 4);

        // Rolling back to v1 would also undo the Chief's branding change: refused.
        $this->actingWithToken($this->principal)->postJson('/api/v1/config/versions/1/rollback', [], ['If-Match' => '"v4"'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'config_section_forbidden');
    });

    it('returns 404 for a version that does not exist', function () {
        $this->actingWithToken($this->chief)->postJson('/api/v1/config/versions/99/rollback', [], ['If-Match' => '"v1"'])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'config_version_not_found');
    });
});

describe('edit rights by section', function () {
    it('lets the Chief change any section', function (string $path, mixed $value) {
        $this->actingWithToken($this->chief)
            ->putJson('/api/v1/config', ['body' => changed($this, $path, $value)], ['If-Match' => '"v1"'])
            ->assertOk()
            ->assertJsonPath('data.version', 2);
    })->with('every section');

    it('lets the Principal change policies and academic settings', function (string $path, mixed $value) {
        $this->actingWithToken($this->principal)
            ->putJson('/api/v1/config', ['body' => changed($this, $path, $value)], ['If-Match' => '"v1"'])
            ->assertOk()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.changed_by.level', 80);
    })->with('principal changes');

    it('does not let the Principal change branding, modules, school identity, locale or sign-in', function (string $path, mixed $value) {
        $this->actingWithToken($this->principal)
            ->putJson('/api/v1/config', ['body' => changed($this, $path, $value)], ['If-Match' => '"v1"'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'config_section_forbidden')
            ->assertJsonPath('error.message', "Only the Chief Admin can change: {$path}.");

        expect(versionCount($this))->toBe(1);
    })->with('chief-only changes');

    it('refuses the whole save when one changed path is not allowed', function () {
        $body = changed($this, 'policies.attendance.edit_window_hours', 6);
        Arr::set($body, 'school.name', 'Renamed by Principal');

        $this->actingWithToken($this->principal)
            ->putJson('/api/v1/config', ['body' => $body], ['If-Match' => '"v1"'])
            ->assertForbidden()
            ->assertJsonPath('error.message', 'Only the Chief Admin can change: school.name.');

        expect(versionCount($this))->toBe(1)
            ->and($this->configBody($this->school['tenant'])['policies']['attendance']['edit_window_hours'])->toBe(4);
    });

    it('lets the Chief and the Principal view, and nobody else', function (Role $role, int $status) {
        $user = $this->schoolUser($this->school['tenant'], $role);

        $this->actingWithToken($user)->getJson('/api/v1/config')->assertStatus($status);
        $this->actingWithToken($user)->getJson('/api/v1/config/versions')->assertStatus($status);
    })->with([
        'Chief' => [Role::Chief, 200],
        'Principal' => [Role::Principal, 200],
        'Vice Principal' => [Role::VicePrincipal, 403],
        'Clerk' => [Role::Clerk, 403],
        'Class Teacher' => [Role::ClassTeacher, 403],
        'Parent' => [Role::Parent, 403],
    ]);

    it('does not let anyone below the Principal change anything', function () {
        $clerk = $this->schoolUser($this->school['tenant'], Role::Clerk);

        $this->actingWithToken($clerk)
            ->putJson('/api/v1/config', ['body' => changed($this, 'policies.attendance.edit_window_hours', 6)], ['If-Match' => '"v1"'])
            ->assertForbidden();
    });

    it('matches whole sections, not name prefixes', function () {
        expect(ConfigSections::principalMayEdit('academic'))->toBeTrue()
            ->and(ConfigSections::principalMayEdit('academic.terms'))->toBeTrue()
            ->and(ConfigSections::principalMayEdit('academically_odd'))->toBeFalse()
            ->and(ConfigSections::principalMayEdit('policies.feesx'))->toBeFalse()
            ->and(ConfigSections::principalMayEdit('policies'))->toBeFalse();
    });
});

it('works out the diff path by path, treating lists as one value', function () {
    $diff = ConfigDiff::between(
        ['a' => ['b' => 1, 'c' => [1, 2]], 'gone' => true],
        ['a' => ['b' => 2, 'c' => [1, 2, 3]], 'new' => ['x' => 'y']],
    );

    expect($diff)->toBe([
        ['path' => 'a.b', 'from' => 1, 'to' => 2],
        ['path' => 'a.c', 'from' => [1, 2], 'to' => [1, 2, 3]],
        ['path' => 'gone', 'from' => true, 'to' => null],
        ['path' => 'new.x', 'from' => null, 'to' => 'y'],
    ]);
});

dataset('principal changes', [
    'attendance policy' => ['policies.attendance.edit_window_hours', 6],
    'leave policy' => ['policies.leave.chain', ['principal']],
    'marks policy' => ['policies.marks.auto_publish_class_tests', false],
    'fees policy' => ['policies.fees.receipt_prefix', 'SPS/2026-27/'],
    'academic terms' => ['academic.terms', ['Term 1', 'Term 2', 'Term 3']],
    'academic year start' => ['academic.year_start_month', 6],
]);

dataset('chief-only changes', [
    'branding' => ['branding.accent', '#1D4ED8'],
    'module switch' => ['modules.fees', false],
    'school name' => ['school.name', 'Sunrise Senior Secondary School'],
    'school logo' => ['school.logo_url', 'tenants/x/branding/logo.png'],
    'school board' => ['school.board', 'ICSE'],
    'locale' => ['locale.default_language', 'hi'],
    'sign-in methods' => ['sign_in.methods', ['password']],
]);

dataset('every section', fn () => [
    'branding' => ['branding.accent', '#1D4ED8'],
    'module switch' => ['modules.fees', false],
    'school name' => ['school.name', 'Sunrise Senior Secondary School'],
    'locale' => ['locale.default_language', 'hi'],
    'sign-in methods' => ['sign_in.methods', ['password']],
    'attendance policy' => ['policies.attendance.edit_window_hours', 6],
    'leave policy' => ['policies.leave.chain', ['principal']],
    'academic terms' => ['academic.terms', ['Term 1', 'Term 2', 'Term 3']],
]);
