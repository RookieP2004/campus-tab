<?php

use App\Modules\AcademicStructure\Models\AcademicYear;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Fees\Domain\Ledger;
use App\Modules\Fees\Models\FeeDemand;
use App\Modules\Fees\Models\FeeLedgerEntry;
use App\Modules\Identity\Domain\Role;
use Carbon\CarbonImmutable;
use Tests\Concerns\BuildsSchoolRecords;
use Tests\Concerns\CreatesSchools;
use Tests\Concerns\RefreshMongoDatabase;

uses(RefreshMongoDatabase::class, CreatesSchools::class, BuildsSchoolRecords::class);

/*
| Session 5, fix 1: the promotion preview lists every student with unpaid
| dues; one Principal approval (step-up) carries each balance forward or
| leaves it in the closed year, with an audit entry per student and the dues
| opening in the new year; the year cannot close while a balance is
| undecided.
*/

beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-03-20 10:00', 'Asia/Kolkata'));
    $this->school = $this->activeSchool('DUES-BPL', ['modules' => ['reports', 'fees']]);
    $this->tenant = $this->school['tenant'];
    $this->section = $this->makeClass($this->tenant, '9', ['A'])['A'];
    $this->makeClass($this->tenant, '10', ['A']);
    $this->principal = $this->staffMember($this->tenant, Role::Principal);
    $this->clerk = $this->staffMember($this->tenant, Role::Clerk);
    $this->year = $this->currentYear($this->tenant);
    $this->owes = $this->enrol($this->tenant, $this->section, 'Asha Owes');
    $this->small = $this->enrol($this->tenant, $this->section, 'Bina Small');
    $this->paid = $this->enrol($this->tenant, $this->section, 'Chetan Paid');
    $this->to = ['name' => '2027-28', 'starts_on' => '2027-04-01', 'ends_on' => '2028-03-31'];

    $this->inSchool($this->tenant, function () {
        $ledger = app(Ledger::class);
        $year = (string) $this->year->getKey();
        $ledger->post(['student_id' => (string) $this->owes->getKey(), 'year_id' => $year, 'type' => 'demand', 'amount_paise' => 500000], null);
        $ledger->post(['student_id' => (string) $this->small->getKey(), 'year_id' => $year, 'type' => 'demand', 'amount_paise' => 120000], null);
        $ledger->post(['student_id' => (string) $this->paid->getKey(), 'year_id' => $year, 'type' => 'demand', 'amount_paise' => 300000], null);
        $ledger->post(['student_id' => (string) $this->paid->getKey(), 'year_id' => $year, 'type' => 'payment', 'amount_paise' => -300000], null);
    });
});

afterEach(fn () => CarbonImmutable::setTestNow());

function duesPreview(object $test): array
{
    return $test->actingWithToken($test->principal['user'])->postJson('/api/v1/promotions/preview', ['to' => $test->to])->assertOk()->json('data');
}

it('lists every student with unpaid dues and refuses to close the year until each is decided', function () {
    $preview = duesPreview($this);

    expect($preview['dues']['students'])->toHaveCount(2)
        ->and(collect($preview['dues']['students'])->pluck('name')->all())->toBe(['Asha Owes', 'Bina Small'])
        ->and(collect($preview['dues']['students'])->firstWhere('name', 'Asha Owes'))->toMatchArray(['balance_paise' => 500000, 'status' => 'open', 'section' => '9-A'])
        ->and($preview['dues'])->toMatchArray(['total_paise' => 620000, 'unresolved' => 2]);

    $this->actingWithToken($this->principal['user'])->postJson('/api/v1/promotions/apply', ['to' => $this->to, 'digest' => $preview['digest']])
        ->assertStatus(422)->assertJsonPath('error.code', 'dues_unresolved')->assertJsonPath('error.details.count', 2);
    expect($this->inSchool($this->tenant, fn () => AcademicYear::query()->where('status', 'closed')->exists()))->toBeFalse();
});

it('carries dues forward with one step-up approval, an audit entry per student, and opens them in the new year', function () {
    $preview = duesPreview($this);
    $body = ['to' => $this->to, 'decisions' => [(string) $this->small->getKey() => 'leave'], 'dues_digest' => $preview['dues']['digest']];

    // Only the Principal (or the Chief), and only after a fresh code.
    $this->actingWithToken($this->clerk['user'], true)->postJson('/api/v1/promotions/carry-forward', $body)->assertForbidden();
    $this->actingWithToken($this->principal['user'])->postJson('/api/v1/promotions/carry-forward', $body)->assertForbidden()->assertJsonPath('error.code', 'step_up_required');

    $result = $this->actingWithToken($this->principal['user'], true)->postJson('/api/v1/promotions/carry-forward', $body)->assertOk()->json('data');
    expect($result)->toMatchArray(['carried' => 1, 'left' => 1, 'carried_paise' => 500000, 'left_paise' => 120000])
        ->and($result['to']['name'])->toBe('2027-28');

    $this->inSchool($this->tenant, function () use ($result) {
        $old = (string) $this->year->getKey();
        $new = $result['to']['id'];
        $ledger = app(Ledger::class);
        expect($ledger->balance((string) $this->owes->getKey(), $old))->toBe(0)
            ->and($ledger->balance((string) $this->owes->getKey(), $new))->toBe(500000)
            ->and($ledger->balance((string) $this->small->getKey(), $old))->toBe(120000)
            ->and($ledger->balance((string) $this->small->getKey(), $new))->toBe(0);
        $demand = FeeDemand::query()->where('year_id', $new)->where('student_id', (string) $this->owes->getKey())->firstOrFail();
        expect($demand->label)->toBe('Carried forward from 2026-27')->and($demand->total_paise)->toBe(500000)->and($demand->due_on)->toBe('2027-04-01');
        expect(AuditLog::query()->where('action', 'fees.carried_forward')->where('entity_id', (string) $this->owes->getKey())->count())->toBe(1)
            ->and(AuditLog::query()->where('action', 'fees.dues_left_in_closed_year')->where('entity_id', (string) $this->small->getKey())->count())->toBe(1)
            ->and(AuditLog::query()->where('action', 'fees.year_end_dues_settled')->count())->toBe(1);
        // Append-only: the old entries are untouched, the move is new entries.
        expect(FeeLedgerEntry::query()->where('student_id', (string) $this->owes->getKey())->count())->toBe(3);
    });

    // Every balance is decided: the preview (now into the planned year) applies and the year closes.
    $this->to = ['to_year_id' => $result['to']['id']];
    $preview = duesPreview($this);
    expect($preview['dues'])->toMatchArray(['unresolved' => 0])
        ->and(collect($preview['dues']['students'])->firstWhere('name', 'Bina Small')['status'])->toBe('left');
    $this->actingWithToken($this->principal['user'])->postJson('/api/v1/promotions/apply', ['to' => $this->to, 'digest' => $preview['digest']])->assertOk();
    expect($this->inSchool($this->tenant, fn () => AcademicYear::query()->findOrFail($this->year->getKey())->status))->toBe('closed');
});

it('refuses a stale list, and a balance that changed after "leave" must be decided again', function () {
    $preview = duesPreview($this);
    $this->inSchool($this->tenant, fn () => app(Ledger::class)->post(['student_id' => (string) $this->owes->getKey(), 'year_id' => (string) $this->year->getKey(), 'type' => 'payment', 'amount_paise' => -100000], null));

    $this->actingWithToken($this->principal['user'], true)->postJson('/api/v1/promotions/carry-forward', ['to' => $this->to, 'dues_digest' => $preview['dues']['digest']])
        ->assertStatus(409)->assertJsonPath('error.code', 'dues_changed');

    $preview = duesPreview($this);
    $leaveAll = [(string) $this->owes->getKey() => 'leave', (string) $this->small->getKey() => 'leave'];
    $year = $this->actingWithToken($this->principal['user'], true)->postJson('/api/v1/promotions/carry-forward', ['to' => $this->to, 'decisions' => $leaveAll, 'dues_digest' => $preview['dues']['digest']])->assertOk()->json('data.to.id');

    // A payment after the confirmation: that balance is open again.
    $this->inSchool($this->tenant, fn () => app(Ledger::class)->post(['student_id' => (string) $this->owes->getKey(), 'year_id' => (string) $this->year->getKey(), 'type' => 'payment', 'amount_paise' => -50000], null));
    $this->to = ['to_year_id' => $year];
    $preview = duesPreview($this);
    expect($preview['dues']['unresolved'])->toBe(1);
    $this->actingWithToken($this->principal['user'])->postJson('/api/v1/promotions/apply', ['to' => $this->to, 'digest' => $preview['digest']])
        ->assertStatus(422)->assertJsonPath('error.code', 'dues_unresolved');
});

it('closes a year with no unpaid dues without the dues step', function () {
    $this->inSchool($this->tenant, function () {
        $year = (string) $this->year->getKey();
        app(Ledger::class)->post(['student_id' => (string) $this->owes->getKey(), 'year_id' => $year, 'type' => 'payment', 'amount_paise' => -500000], null);
        app(Ledger::class)->post(['student_id' => (string) $this->small->getKey(), 'year_id' => $year, 'type' => 'payment', 'amount_paise' => -120000], null);
    });
    $preview = duesPreview($this);
    expect($preview['dues'])->toMatchArray(['students' => [], 'unresolved' => 0]);
    $this->actingWithToken($this->principal['user'], true)->postJson('/api/v1/promotions/carry-forward', ['to' => $this->to, 'dues_digest' => $preview['dues']['digest']])
        ->assertStatus(422)->assertJsonPath('error.code', 'no_unpaid_dues');
    $this->actingWithToken($this->principal['user'])->postJson('/api/v1/promotions/apply', ['to' => $this->to, 'digest' => $preview['digest']])->assertOk();
});
