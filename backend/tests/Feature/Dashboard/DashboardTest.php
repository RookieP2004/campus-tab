<?php

use App\Modules\Identity\Domain\Role;
use App\Modules\Licensing\Domain\LicenceIssuer;
use App\Modules\Licensing\Models\Licence;
use App\Modules\People\Domain\ParentLogins;
use App\Modules\People\Models\Staff;
use App\Modules\Tenancy\Domain\SchoolConfigService;
use Tests\Concerns\BuildsSchoolRecords;
use Tests\Concerns\CreatesSchools;
use Tests\Concerns\RefreshMongoDatabase;

uses(RefreshMongoDatabase::class, CreatesSchools::class, BuildsSchoolRecords::class);

/*
| Phase 3 item 3 and rule 1: GET /me/dashboard builds each role's cards on
| the server from what that role may see.
*/

beforeEach(function () {
    $this->school = $this->activeSchool('DASH-BPL', ['modules' => ['attendance']]);
    $this->tenant = $this->school['tenant'];
    $this->chief = $this->school['chief'];
    $this->s7 = $this->makeClass($this->tenant, '7', ['A', 'B']);
});

function cardIds(object $test, $user, ?string $as = null): array
{
    return collect($test->actingWithToken($user)->getJson('/api/v1/me/dashboard'.($as ? '?as='.$as : ''))->assertOk()->json('data.cards'))->pluck('id')->all();
}

it('gives the Chief the licence first, and a card for a module the licence added that is not switched on', function () {
    // The licence now includes leave; the school has not switched it on.
    app(LicenceIssuer::class)->amend(Licence::currentFor($this->tenant->id), ['modules' => ['attendance', 'leave']]);
    $this->inSchool($this->tenant, function () {
        $configs = app(SchoolConfigService::class);
        $body = $configs->current()->body;
        $body['modules']['leave'] = false;
        $configs->save($this->chief, $body, $configs->current()->version);
    });

    $cards = $this->actingWithToken($this->chief)->getJson('/api/v1/me/dashboard')->json('data.cards');

    expect($cards[0]['id'])->toBe('licence')
        ->and($cards[0]['data']['students'])->toHaveKeys(['limit', 'used'])
        ->and(collect($cards)->firstWhere('id', 'new_module.leave'))->toMatchArray(['title' => 'dashboard.cards.newModule', 'params' => ['module' => 'leave'], 'link' => '/settings/school'])
        ->and(collect($cards)->pluck('id'))->not->toContain('new_module.attendance');

    // The console never switched it on.
    expect($this->configBody($this->tenant)['modules']['leave'])->toBeFalse();
});

it('puts approvals first for the Principal, with the Clerk\'s staff drafts', function () {
    $principal = $this->staffMember($this->tenant, Role::Principal);
    $this->inSchool($this->tenant, fn () => Staff::create(['employee_code' => 'D1', 'name' => 'Made Up Draft', 'status' => 'draft', 'requested_roles' => ['staff'], 'drafted_by' => ['id' => 'x', 'name' => 'Made Up Clerk']]));

    $cards = $this->actingWithToken($principal['user'])->getJson('/api/v1/me/dashboard')->json('data.cards');
    expect($cards[0]['id'])->toBe('approvals')
        ->and($cards[0]['data']['count'])->toBe(1)
        ->and(collect($cards)->pluck('id'))->not->toContain('licence');
});

it('gives each role only its own cards', function () {
    $teacher = $this->staffMember($this->tenant, Role::ClassTeacher);
    $this->makeClassTeacher($this->tenant, $this->s7['B'], $teacher['staff']);
    $subject = $this->staffMember($this->tenant, Role::SubjectTeacher);
    $this->assignTeacher($this->tenant, $this->s7['A'], $this->makeSubject($this->tenant, 'SCI', 'Science'), $subject['staff']);
    $clerk = $this->staffMember($this->tenant, Role::Clerk);
    $staff = $this->staffMember($this->tenant, Role::Staff);
    $aarav = $this->enrol($this->tenant, $this->s7['B'], 'Aarav Patel', '+919811110001');
    $this->enrol($this->tenant, $this->s7['A'], 'Riya Patel', '+919811110001');
    $parent = $this->inSchool($this->tenant, fn () => app(ParentLogins::class)->enable($this->chief, $this->guardianOf($this->tenant, $aarav)));

    expect(cardIds($this, $teacher['user']))->toContain('my_class.'.$this->s7['B']->getKey())->not->toContain('on_roll')->not->toContain('approvals')
        ->and(cardIds($this, $subject['user']))->toContain('my_classes')->not->toContain('my_class.'.$this->s7['B']->getKey())
        ->and(cardIds($this, $clerk['user']))->toContain('on_roll', 'admissions')->not->toContain('licence', 'approvals')
        ->and(cardIds($this, $staff['user']))->toBe(['check_in', 'notices'])
        ->and(cardIds($this, $parent))->toBe(['children', 'notices']);

    $children = collect($this->actingWithToken($parent)->getJson('/api/v1/me/dashboard')->json('data.cards'))->firstWhere('id', 'children');
    expect(collect($children['data']['items'])->pluck('label')->sort()->values()->all())->toBe(['Aarav Patel', 'Riya Patel']);
});

it('switches between a person\'s own roles, never to a role they do not hold', function () {
    $teacher = $this->staffMember($this->tenant, Role::ClassTeacher);
    $this->makeClassTeacher($this->tenant, $this->s7['B'], $teacher['staff']);
    $child = $this->enrol($this->tenant, $this->s7['A'], 'Teacher Child', $teacher['user']->phone);
    $this->inSchool($this->tenant, fn () => app(ParentLogins::class)->enable($this->chief, $this->guardianOf($this->tenant, $child)));
    $user = $this->freshUser($this->tenant, $teacher['user']);

    $view = $this->actingWithToken($user)->getJson('/api/v1/me/dashboard')->json('data');
    expect($view['role'])->toBe('class_teacher')->and(collect($view['roles'])->pluck('name')->all())->toBe(['class_teacher', 'parent']);

    expect(cardIds($this, $user, 'parent'))->toBe(['children', 'notices'])
        ->and(cardIds($this, $user, 'chief'))->toContain('my_class.'.$this->s7['B']->getKey())->not->toContain('licence');
});

it('searches only what the person may see', function () {
    $teacher = $this->staffMember($this->tenant, Role::ClassTeacher);
    $this->makeClassTeacher($this->tenant, $this->s7['B'], $teacher['staff']);
    $this->enrol($this->tenant, $this->s7['B'], 'Aarav Patel');
    $this->enrol($this->tenant, $this->s7['A'], 'Aarav Mehta');

    expect(collect($this->actingWithToken($teacher['user'])->getJson('/api/v1/me/search?q=aarav')->assertOk()->json('data'))->pluck('label')->all())->toBe(['Aarav Patel'])
        ->and(collect($this->actingWithToken($this->chief)->getJson('/api/v1/me/search?q=aarav')->json('data'))->pluck('label')->sort()->values()->all())->toBe(['Aarav Mehta', 'Aarav Patel']);
});

it('puts the class teacher\'s register for today at the top, and today\'s attendance on the Principal\'s screen', function () {
    $teacher = $this->staffMember($this->tenant, Role::ClassTeacher);
    $this->makeClassTeacher($this->tenant, $this->s7['B'], $teacher['staff']);
    $principal = $this->staffMember($this->tenant, Role::Principal);
    $this->enrol($this->tenant, $this->s7['B'], 'Aarav Patel');

    $cards = $this->actingWithToken($teacher['user'])->getJson('/api/v1/me/dashboard')->json('data.cards');
    expect($cards[0])->toMatchArray(['id' => 'take_attendance.'.$this->s7['B']->getKey(), 'link' => '/attendance/'.$this->s7['B']->getKey()])
        ->and($cards[0]['data']['marked'])->toBeFalse();

    $today = collect($this->actingWithToken($principal['user'])->getJson('/api/v1/me/dashboard')->json('data.cards'))->firstWhere('id', 'attendance_today');
    expect($today['data']['totals'])->toMatchArray(['sections' => 2, 'marked_sections' => 0])
        ->and($today['data']['unmarked'])->toHaveCount(2);
});
