<?php

use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Communication\Models\Notification;
use App\Modules\Identity\Domain\Role;
use App\Modules\People\Domain\ParentLogins;
use App\Modules\Tenancy\Domain\SchoolConfigService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\BuildsSchoolRecords;
use Tests\Concerns\CreatesSchools;
use Tests\Concerns\RefreshMongoDatabase;

uses(RefreshMongoDatabase::class, CreatesSchools::class, BuildsSchoolRecords::class);

/*
| Phase 4 items 1-4, 7 and 8: the class register, the edit window, late
| entry from the paper register, absence notices, the holiday calendar and
| the reports.
*/

beforeEach(function () {
    $this->school = $this->activeSchool('ATT-BPL', ['modules' => ['attendance']]);
    $this->tenant = $this->school['tenant'];
    $this->chief = $this->school['chief'];
    $this->s7 = $this->makeClass($this->tenant, '7', ['A', 'B']);
    $this->section = $this->s7['A'];
    $this->teacher = $this->staffMember($this->tenant, Role::ClassTeacher);
    $this->makeClassTeacher($this->tenant, $this->section, $this->teacher['staff']);
    $this->clerk = $this->staffMember($this->tenant, Role::Clerk);
    $this->principal = $this->staffMember($this->tenant, Role::Principal);
    $this->aarav = $this->enrol($this->tenant, $this->section, 'Aarav Patel', '+919811110001');
    $this->riya = $this->enrol($this->tenant, $this->section, 'Riya Shah', '+919811110002');
    $this->parent = $this->inSchool($this->tenant, fn () => app(ParentLogins::class)->enable($this->chief, $this->guardianOf($this->tenant, $this->aarav)));
    // 08:30 on Thursday 3 December, school time; the window closes at 12:00.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-03 08:30', 'Asia/Kolkata'));
});

afterEach(fn () => CarbonImmutable::setTestNow());

function dayUrl(object $test, string $date, $section = null): string
{
    return '/api/v1/attendance/sections/'.($section ?? $test->section)->getKey().'/days/'.$date;
}

it('opens the register with everyone present and marks a class of 45 in one save', function () {
    foreach (range(3, 45) as $n) {
        $this->enrol($this->tenant, $this->section, "Made Up Student {$n}");
    }

    $day = $this->actingWithToken($this->teacher['user'])->getJson(dayUrl($this, '2026-12-03'))->assertOk()->json('data');
    expect($day['students'])->toHaveCount(45)
        ->and(collect($day['students'])->pluck('status')->unique()->all())->toBe(['P'])
        ->and($day['marked'])->toBeFalse()
        ->and($day['rules'])->toMatchArray(['can_save' => true, 'needs_reason' => false]);

    // Tap two absentees, save once.
    $this->actingWithToken($this->teacher['user'])->putJson(dayUrl($this, '2026-12-03'), ['statuses' => [
        (string) $this->aarav->getKey() => 'A', (string) $this->riya->getKey() => 'L',
    ]])->assertOk()->assertJsonPath('data.marked', true)->assertJsonPath('data.late_entry', null);

    $this->inSchool($this->tenant, function () {
        $saved = AttendanceDay::query()->first();
        expect($saved->entries)->toHaveCount(45)
            ->and($saved->statusByStudent()[(string) $this->aarav->getKey()])->toBe('A')
            ->and(collect($saved->entries)->where('status', 'P'))->toHaveCount(43);
        // The parent hears at once, without private details on the lock screen (Phase 3 rule 4).
        expect(Notification::query()->where('user_id', (string) $this->parent->getKey())->first())
            ->type->toBe('attendance.absent')->title->toBe('Aarav Patel is marked absent today (3 December)');
    });
});

it('lets the teacher change the register until the window closes, and only the office after that, with a reason', function () {
    $this->actingWithToken($this->teacher['user'])->putJson(dayUrl($this, '2026-12-03'), ['statuses' => []])->assertOk();
    $this->actingWithToken($this->teacher['user'])->putJson(dayUrl($this, '2026-12-03'), ['statuses' => [(string) $this->riya->getKey() => 'A']])->assertOk();

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-03 12:01', 'Asia/Kolkata'));

    // A late edit without permission is refused.
    $this->actingWithToken($this->teacher['user'])->putJson(dayUrl($this, '2026-12-03'), ['statuses' => [], 'reason' => 'Riya came after all'])
        ->assertForbidden()->assertJsonPath('error.code', 'attendance_window_closed');

    // The Clerk may, but only with a reason.
    $this->actingWithToken($this->clerk['user'], steppedUp: true)->putJson(dayUrl($this, '2026-12-03'), ['statuses' => []])
        ->assertUnprocessable()->assertJsonPath('error.code', 'reason_required');
    $this->actingWithToken($this->clerk['user'], steppedUp: true)->putJson(dayUrl($this, '2026-12-03'), ['statuses' => [], 'reason' => 'Riya was at the sports trial, note from the coach'])->assertOk();

    $this->inSchool($this->tenant, function () {
        $audits = AuditLog::query()->where('entity', 'attendance_days')->orderBy('_id')->get();
        expect($audits)->toHaveCount(3)
            ->and($audits->last()->meta)->toMatchArray(['reason' => 'Riya was at the sports trial, note from the coach', 'after_window' => true])
            ->and($audits->last()->actor_id)->toBe((string) $this->clerk['user']->getKey());
    });
});

it('lets the teacher enter a missed day from the paper register, with a reason, as a late entry', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-05 10:00', 'Asia/Kolkata'));

    $day = $this->actingWithToken($this->teacher['user'])->getJson(dayUrl($this, '2026-12-03'))->json('data');
    expect($day['rules'])->toMatchArray(['can_save' => true, 'needs_reason' => true, 'reason_kind' => 'late_entry', 'late_entry_until' => '2026-12-10']);

    $this->actingWithToken($this->teacher['user'])->putJson(dayUrl($this, '2026-12-03'), ['statuses' => [(string) $this->aarav->getKey() => 'A']])
        ->assertUnprocessable()->assertJsonPath('error.code', 'reason_required');

    $saved = $this->actingWithToken($this->teacher['user'])->putJson(dayUrl($this, '2026-12-03'), [
        'statuses' => [(string) $this->aarav->getKey() => 'A'], 'reason' => 'No internet on 3 December; entered from the paper register',
    ])->assertOk()->json('data');

    expect($saved['late_entry'])->toMatchArray(['reason' => 'No internet on 3 December; entered from the paper register', 'entered_by' => $this->teacher['user']->name, 'by_office' => false, 'has_photo' => false]);

    // A photo of the paper page.
    $this->actingWithToken($this->teacher['user'])->post('/api/v1/attendance/days/'.$saved['day_id'].'/photo', ['photo' => UploadedFile::fake()->createWithContent('register.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='))], ['Accept' => 'application/json'])->assertCreated();
    $link = $this->actingWithToken($this->teacher['user'])->postJson('/api/v1/attendance/days/'.$saved['day_id'].'/photo/link')->assertOk()->json('data.url');
    expect($link)->toStartWith('/api/v1/files/attendance/'.$this->tenant->id.'/');
    $this->get($link)->assertOk();
    $this->get(preg_replace('/signature=[a-f0-9]+/', 'signature=00', $link))->assertForbidden();

    // The parent's notice names the date.
    $this->inSchool($this->tenant, fn () => expect(Notification::query()->where('user_id', (string) $this->parent->getKey())->value('title'))->toBe('Aarav Patel was absent on 3 December'));

    // A late entry never overwrites a marked day: changing it now is a late edit for the office.
    $this->actingWithToken($this->teacher['user'])->putJson(dayUrl($this, '2026-12-03'), ['statuses' => [], 'reason' => 'Changing my mind about it'])
        ->assertForbidden()->assertJsonPath('error.code', 'attendance_window_closed');

    $this->inSchool($this->tenant, fn () => expect(AuditLog::query()->where('entity', 'attendance_days')->first()->meta)->toMatchArray(['late_entry' => true]));
});

it('sends the absence notice for a day only once', function () {
    $this->actingWithToken($this->teacher['user'])->putJson(dayUrl($this, '2026-12-03'), ['statuses' => [(string) $this->aarav->getKey() => 'A']])->assertOk();
    $this->actingWithToken($this->teacher['user'])->putJson(dayUrl($this, '2026-12-03'), ['statuses' => [(string) $this->aarav->getKey() => 'A', (string) $this->riya->getKey() => 'A']])->assertOk();

    $this->inSchool($this->tenant, fn () => expect(Notification::query()->where('user_id', (string) $this->parent->getKey())->count())->toBe(1));
});

it('leaves a day past the late-entry limit to the Clerk or the Principal, with a reason', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-14 09:00', 'Asia/Kolkata'));

    $this->actingWithToken($this->teacher['user'])->putJson(dayUrl($this, '2026-12-03'), ['statuses' => [], 'reason' => 'Found the paper register today'])
        ->assertForbidden()->assertJsonPath('error.code', 'late_entry_past_limit');

    $this->actingWithToken($this->principal['user'], steppedUp: true)->putJson(dayUrl($this, '2026-12-03'), ['statuses' => [], 'reason' => 'Found the paper register today'])
        ->assertOk()->assertJsonPath('data.late_entry.by_office', true);
});

it('refuses a future date, a section the teacher does not have, and a subject teacher in daily mode', function () {
    $this->actingWithToken($this->teacher['user'])->putJson(dayUrl($this, '2026-12-04'), ['statuses' => []])->assertUnprocessable()->assertJsonPath('error.code', 'future_date');
    $this->actingWithToken($this->teacher['user'])->getJson(dayUrl($this, '2026-12-03', $this->s7['B']))->assertNotFound();

    $subject = $this->staffMember($this->tenant, Role::SubjectTeacher);
    $this->assignTeacher($this->tenant, $this->section, $this->makeSubject($this->tenant, 'SCI', 'Science'), $subject['staff']);
    $this->actingWithToken($subject['user'])->putJson(dayUrl($this, '2026-12-03'), ['statuses' => []])->assertForbidden()->assertJsonPath('error.code', 'attendance_forbidden');
});

it('closes the register on an approved holiday: the Clerk keeps the calendar, the Principal approves', function () {
    $id = $this->actingWithToken($this->clerk['user'], steppedUp: true)->postJson('/api/v1/calendar', ['date' => '2026-12-03', 'type' => 'holiday', 'title' => 'Founders day'])
        ->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');

    // Pending: the register is still open, and teachers do not see it yet.
    $this->actingWithToken($this->teacher['user'])->getJson(dayUrl($this, '2026-12-03'))->assertJsonPath('data.holiday', null);
    expect($this->actingWithToken($this->teacher['user'])->getJson('/api/v1/calendar?from=2026-12-01&to=2026-12-31')->assertOk()->json('data'))->toBe([]);

    $this->actingWithToken($this->clerk['user'], steppedUp: true)->postJson("/api/v1/calendar/{$id}/approve")->assertForbidden();
    $this->actingWithToken($this->teacher['user'])->postJson('/api/v1/calendar', ['date' => '2026-12-25', 'type' => 'holiday', 'title' => 'Christmas'])->assertForbidden();
    $this->actingWithToken($this->principal['user'], steppedUp: true)->postJson("/api/v1/calendar/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');

    $day = $this->actingWithToken($this->teacher['user'])->getJson(dayUrl($this, '2026-12-03'))->json('data');
    expect($day['holiday'])->toBe(['title' => 'Founders day'])->and(collect($day['students'])->pluck('status')->unique()->all())->toBe(['H']);
    $this->actingWithToken($this->teacher['user'])->putJson(dayUrl($this, '2026-12-03'), ['statuses' => []])->assertUnprocessable()->assertJsonPath('error.code', 'holiday');

    // An approved entry is no longer changed.
    $this->actingWithToken($this->clerk['user'], steppedUp: true)->patchJson("/api/v1/calendar/{$id}", ['title' => 'Other'])->assertStatus(409);
});

it('takes the register as an Excel or CSV upload: template, dry run, then commit', function () {
    $this->actingWithToken($this->teacher['user'])->get('/api/v1/attendance/sections/'.$this->section->getKey().'/template?date=2026-12-03')->assertOk();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-04 09:00', 'Asia/Kolkata'));

    $csv = "roll_no,admission_no,name,status\n1,{$this->aarav->admission_no},Aarav Patel,A\n2,{$this->riya->admission_no},Riya Shah,X\n";
    $url = dayUrl($this, '2026-12-03').'/import';
    $dry = $this->actingWithToken($this->teacher['user'])->post($url, ['file' => UploadedFile::fake()->createWithContent('day.csv', $csv), 'reason' => 'No internet yesterday, from paper'], ['Accept' => 'application/json'])->assertOk()->json('data');
    expect($dry)->toMatchArray(['ok' => 1, 'errors' => 1, 'committed' => false])->and($dry['rules']['reason_kind'])->toBe('late_entry');

    $fixed = str_replace(',X', ',P', $csv);
    $done = $this->actingWithToken($this->teacher['user'])->post($url, ['file' => UploadedFile::fake()->createWithContent('day.csv', $fixed), 'reason' => 'No internet yesterday, from paper', 'commit' => '1'], ['Accept' => 'application/json'])->assertOk()->json('data');
    expect($done)->toMatchArray(['ok' => 2, 'committed' => true, 'absent' => 1]);

    $this->inSchool($this->tenant, fn () => expect(AttendanceDay::query()->first())->source->toBe('excel')->late_entry->not->toBeNull());
});

it('reports the daily summary, the monthly register and students below the threshold, for the sections you may see', function () {
    foreach (['2026-12-01' => 'A', '2026-12-02' => 'A', '2026-12-03' => 'P'] as $date => $status) {
        CarbonImmutable::setTestNow(CarbonImmutable::parse($date.' 08:30', 'Asia/Kolkata'));
        $this->actingWithToken($this->teacher['user'])->putJson(dayUrl($this, $date), ['statuses' => [(string) $this->aarav->getKey() => $status]])->assertOk();
    }

    $summary = $this->actingWithToken($this->principal['user'], steppedUp: true)->getJson('/api/v1/attendance/summary?date=2026-12-03')->assertOk()->json('data');
    expect(collect($summary['sections'])->firstWhere('section_id', (string) $this->section->getKey()))->toMatchArray(['marked' => true, 'percent' => 100])
        ->and(collect($summary['sections'])->firstWhere('section_id', (string) $this->s7['B']->getKey())['marked'])->toBeFalse()
        ->and($summary['staff'])->toHaveKeys(['total', 'present', 'late']);

    // The class teacher sees only their own section.
    $mine = $this->actingWithToken($this->teacher['user'])->getJson('/api/v1/attendance/summary?date=2026-12-03')->json('data.sections');
    expect(collect($mine)->pluck('section_id')->all())->toBe([(string) $this->section->getKey()]);

    $register = $this->actingWithToken($this->teacher['user'])->getJson('/api/v1/attendance/register?section_id='.$this->section->getKey().'&month=2026-12')->assertOk()->json('data');
    $row = collect($register['students'])->firstWhere('id', (string) $this->aarav->getKey());
    expect($register['dates'])->toHaveCount(31)->and($row['marks']['2026-12-01'])->toBe('A')->and($row['marks']['2026-12-04'])->toBeNull()->and($row['percent'])->toBe(33.3);

    $below = $this->actingWithToken($this->teacher['user'])->getJson('/api/v1/attendance/below-threshold')->assertOk()->json('data');
    expect($below['threshold'])->toBe(75)->and(collect($below['students'])->pluck('id')->all())->toBe([(string) $this->aarav->getKey()]);
});

it('lets a parent read their own child\'s days, never the class register or another child', function () {
    $this->actingWithToken($this->teacher['user'])->putJson(dayUrl($this, '2026-12-03'), ['statuses' => [(string) $this->aarav->getKey() => 'A']])->assertOk();

    $mine = $this->actingWithToken($this->parent)->getJson('/api/v1/attendance/students/'.$this->aarav->getKey().'?month=2026-12')->assertOk()->json('data');
    expect($mine['days'])->toBe([['date' => '2026-12-03', 'status' => 'A', 'late_entry' => false]])->and($mine['percent'])->toEqual(0);

    $this->actingWithToken($this->parent)->getJson('/api/v1/attendance/students/'.$this->riya->getKey())->assertNotFound();
    $this->actingWithToken($this->parent)->getJson(dayUrl($this, '2026-12-03'))->assertNotFound();
    $this->actingWithToken($this->parent)->putJson(dayUrl($this, '2026-12-03'), ['statuses' => []])->assertNotFound();
});

it('in period mode tells the parent once a day, however many periods the child misses', function () {
    $this->inSchool($this->tenant, function () {
        $configs = app(SchoolConfigService::class);
        $body = $configs->current()->body;
        $body['policies']['attendance']['student_mode'] = 'period';
        $configs->save($this->chief, $body, $configs->current()->version);
    });

    foreach ([1, 2, 3] as $period) {
        $this->actingWithToken($this->teacher['user'])->putJson(dayUrl($this, '2026-12-03'), ['period' => $period, 'statuses' => [(string) $this->aarav->getKey() => 'A']])
            ->assertOk()->assertJsonPath('data.period', $period);
    }

    $this->inSchool($this->tenant, fn () => expect(Notification::query()->where('user_id', (string) $this->parent->getKey())->count())->toBe(1)
        ->and(AttendanceDay::query()->count())->toBe(3));
});
