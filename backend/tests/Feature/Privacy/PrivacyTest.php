<?php

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Communication\Models\Notification;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\DeviceSession;
use App\Modules\People\Domain\ParentLogins;
use App\Modules\People\Models\Consent;
use App\Modules\People\Models\Student;
use App\Modules\Privacy\Models\RetentionRun;
use App\Modules\Tenancy\Domain\SchoolConfigService;
use Carbon\CarbonImmutable;
use Tests\Concerns\BuildsSchoolRecords;
use Tests\Concerns\CreatesSchools;
use Tests\Concerns\RefreshMongoDatabase;

uses(RefreshMongoDatabase::class, CreatesSchools::class, BuildsSchoolRecords::class);

/*
| Phase 9 DPDP features: versioned notices; consent per purpose given by the
| parent from their own account (the only consent that counts); the data
| requests inbox with due dates; access copy and erasure (audit log and fee
| ledger kept, decision 0017); retention with a dry run before anything is
| deleted; the grievance contact; the incident log.
*/

beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-01-10 10:00', 'Asia/Kolkata'));
    $this->school = $this->activeSchool('PRIV-BPL');
    $this->tenant = $this->school['tenant'];
    $this->chief = $this->school['chief'];
    $this->principal = $this->staffMember($this->tenant, Role::Principal)['user'];
    $this->clerk = $this->staffMember($this->tenant, Role::Clerk)['user'];
    $section = $this->makeClass($this->tenant, '7', ['B'])['B'];
    $this->child = $this->enrol($this->tenant, $section, 'Asha Rao', '+919800012345');
    $this->other = $this->enrol($this->tenant, $section, 'Kabir Das', '+919800012346');
    $this->parent = $this->inSchool($this->tenant, fn () => app(ParentLogins::class)->enable($this->chief, $this->guardianOf($this->tenant, $this->child)));
});

afterEach(fn () => CarbonImmutable::setTestNow());

function publishNotice(object $test, string $title = 'How Sunrise uses personal data'): int
{
    $test->actingWithToken($test->chief)->putJson('/api/v1/privacy/notices/draft', ['title' => $title, 'body' => str_repeat('We collect what the school needs to teach and keep children safe. ', 3)])->assertOk();

    return $test->actingWithToken($test->chief)->postJson('/api/v1/privacy/notices/publish')->assertOk()->json('data.version');
}

it('versions the privacy notice: drafts change, a published version never does', function () {
    expect(publishNotice($this))->toBe(1);
    expect(publishNotice($this, 'Updated notice'))->toBe(2);

    $history = $this->actingWithToken($this->chief)->getJson('/api/v1/privacy/notices')->assertOk()->json('data');
    expect(collect($history)->pluck('status', 'version')->all())->toBe([2 => 'published', 1 => 'replaced']);
    $this->actingWithToken($this->parent)->getJson('/api/v1/privacy')->assertOk()->assertJsonPath('data.notice.version', 2);
    $this->actingWithToken($this->clerk)->putJson('/api/v1/privacy/notices/draft', ['title' => 'x', 'body' => 'y'])->assertForbidden();
    expect($this->inSchool($this->tenant, fn () => Notification::query()->where('type', 'privacy.notice')->where('user_id', (string) $this->parent->getKey())->count()))->toBe(2);
});

it('takes consent only from the parent\'s own account, and PEN only after it', function () {
    $version = publishNotice($this);
    $child = (string) $this->child->getKey();

    // The office cannot record consent any more, nor keep PEN without the parent's yes.
    $this->actingWithToken($this->clerk)->patchJson("/api/v1/students/{$child}", ['consents' => ['pen' => true]])->assertStatus(422)->assertJsonPath('error.code', 'consent_parent_only');
    $this->actingWithToken($this->clerk)->patchJson("/api/v1/students/{$child}", ['ids' => ['pen' => 'PEN12345']])->assertStatus(422)->assertJsonPath('error.code', 'consent_required');

    // A parent answers only for their own child, against the current notice.
    $this->actingWithToken($this->parent)->postJson('/api/v1/privacy/consents', ['student_id' => (string) $this->other->getKey(), 'purpose' => 'pen', 'given' => true, 'notice_version' => $version])->assertForbidden();
    $this->actingWithToken($this->parent)->postJson('/api/v1/privacy/consents', ['student_id' => $child, 'purpose' => 'pen', 'given' => true, 'notice_version' => $version + 1])->assertStatus(409)->assertJsonPath('error.code', 'notice_changed');
    $this->actingWithToken($this->clerk)->postJson('/api/v1/privacy/consents', ['student_id' => $child, 'purpose' => 'pen', 'given' => true, 'notice_version' => $version])->assertForbidden();
    $answer = $this->actingWithToken($this->parent)->postJson('/api/v1/privacy/consents', ['student_id' => $child, 'purpose' => 'pen', 'given' => true, 'notice_version' => $version])->assertOk()->json('data.children.0.consents.pen');
    expect($answer)->toMatchArray(['given' => true, 'version' => (string) $version]);

    $this->actingWithToken($this->clerk)->patchJson("/api/v1/students/{$child}", ['ids' => ['pen' => 'PEN12345']])->assertOk();
    $this->actingWithToken($this->clerk)->getJson("/api/v1/students/{$child}")->assertOk()->assertJsonPath('data.consents.pen.by_parent', true);

    // Withdrawn: the number goes at once.
    $this->actingWithToken($this->parent)->postJson('/api/v1/privacy/consents', ['student_id' => $child, 'purpose' => 'pen', 'given' => false, 'notice_version' => $version])->assertOk();
    expect($this->inSchool($this->tenant, fn () => Student::query()->findOrFail($child)->ids['pen'] ?? null))->toBeNull();

    // An office tick from before Phase 9 no longer counts and asks for the parent.
    $this->inSchool($this->tenant, fn () => Consent::create(['student_id' => (string) $this->other->getKey(), 'purpose' => 'apaar', 'notice_version' => '2026-1', 'given' => true, 'at' => now()->toIso8601String(), 'recorded_by' => ['id' => 'x', 'name' => 'Office']]));
    $this->actingWithToken($this->clerk)->getJson('/api/v1/students/'.$this->other->getKey())->assertOk()
        ->assertJsonPath('data.consents.apaar.given', false)->assertJsonPath('data.consents.apaar.needs_parent', true);
});

it('keeps a data-requests inbox with due dates, a watermarked copy and an erasure that keeps the ledgers', function () {
    $child = (string) $this->child->getKey();
    $this->actingWithToken($this->parent)->postJson('/api/v1/privacy/requests', ['type' => 'access', 'student_id' => (string) $this->other->getKey(), 'details' => 'Please send me everything you hold.'])->assertForbidden();
    $access = $this->actingWithToken($this->parent)->postJson('/api/v1/privacy/requests', ['type' => 'access', 'student_id' => $child, 'details' => 'Please send me everything you hold.'])->assertCreated()->json('data');
    expect($access['due_on'])->toBe('2027-02-09');
    $erasure = $this->actingWithToken($this->parent)->postJson('/api/v1/privacy/requests', ['type' => 'erasure', 'student_id' => $child, 'details' => 'We are moving abroad; erase our details.'])->assertCreated()->json('data.id');

    $this->actingWithToken($this->clerk)->getJson('/api/v1/privacy/requests')->assertForbidden();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-02-15 10:00', 'Asia/Kolkata'));
    $this->actingWithToken($this->principal)->getJson('/api/v1/privacy/requests')->assertOk()->assertJsonPath('meta.late', 2);

    // Access: the copy needs step-up and is watermarked and audited.
    $this->actingWithToken($this->principal)->getJson("/api/v1/privacy/requests/{$access['id']}/copy")->assertForbidden();
    $copy = $this->actingWithToken($this->principal, true)->getJson("/api/v1/privacy/requests/{$access['id']}/copy")->assertOk()->json('data');
    expect($copy['student']['name'])->toBe('Asha Rao')->and($copy['watermark'])->toContain('exported by');
    $this->actingWithToken($this->principal)->postJson("/api/v1/privacy/requests/{$access['id']}/move", ['status' => 'completed'])->assertStatus(422)->assertJsonPath('error.code', 'outcome_required');
    $this->actingWithToken($this->principal)->postJson("/api/v1/privacy/requests/{$access['id']}/move", ['status' => 'completed', 'outcome' => 'A copy was handed over at the office.'])->assertOk();
    $mine = $this->actingWithToken($this->parent)->getJson('/api/v1/privacy')->assertOk()->json('data.requests');
    expect(collect($mine)->firstWhere('id', $access['id']))->toMatchArray(['status' => 'completed', 'outcome' => 'A copy was handed over at the office.', 'requested_by' => null]);

    // Erasure: Chief only, step-up, typed admission number.
    $admission = $this->child->admission_no;
    $this->actingWithToken($this->principal, true)->postJson("/api/v1/privacy/requests/{$erasure}/erase", ['confirm' => $admission])->assertForbidden();
    $this->actingWithToken($this->chief, true)->postJson("/api/v1/privacy/requests/{$erasure}/erase", ['confirm' => 'wrong'])->assertStatus(422);
    $this->actingWithToken($this->chief, true)->postJson("/api/v1/privacy/requests/{$erasure}/erase", ['confirm' => $admission])->assertOk()->assertJsonPath('data.status', 'completed');
    $this->inSchool($this->tenant, function () use ($child) {
        $s = Student::query()->findOrFail($child);
        expect($s->name)->toBe('Erased student')->and($s->dob)->toBeNull()->and($s->status)->toBe('erased');
        // The audit log is untouched (append-only), and the erasure itself is in it.
        expect(AuditLog::query()->where('action', 'privacy.student_erased')->exists())->toBeTrue();
    });
});

it('shows a retention dry run first and deletes only after the Chief confirms with step-up', function () {
    $this->inSchool($this->tenant, function () {
        foreach ([400, 10] as $daysAgo) {
            Notification::create(['user_id' => (string) $this->parent->getKey(), 'type' => 'notice', 'title' => 'Old', 'read_at' => now()->subDays($daysAgo)]);
        }
    });
    $run = $this->actingWithToken($this->chief)->postJson('/api/v1/privacy/retention/notifications/dry-run')->assertCreated()->json('data');
    expect($run)->toMatchArray(['status' => 'dry_run', 'count' => 1, 'days' => 365]);
    expect($this->inSchool($this->tenant, fn () => Notification::query()->count()))->toBe(2);

    $this->actingWithToken($this->principal, true)->postJson("/api/v1/privacy/retention/runs/{$run['id']}/confirm")->assertForbidden();
    $this->actingWithToken($this->chief)->postJson("/api/v1/privacy/retention/runs/{$run['id']}/confirm")->assertForbidden()->assertJsonPath('error.code', 'step_up_required');
    $this->actingWithToken($this->chief, true)->postJson("/api/v1/privacy/retention/runs/{$run['id']}/confirm")->assertOk()->assertJsonPath('data.deleted', 1);
    $this->actingWithToken($this->chief, true)->postJson("/api/v1/privacy/retention/runs/{$run['id']}/confirm")->assertStatus(409);
    expect($this->inSchool($this->tenant, fn () => Notification::query()->count()))->toBe(1);

    // The nightly job only reports; it never deletes.
    $this->inSchool($this->tenant, fn () => DeviceSession::query()->create(['user_id' => (string) $this->parent->getKey(), 'kind' => 'token', 'name' => 'old phone', 'revoked_at' => now()->subDays(200), 'last_seen_at' => now()->subDays(200)]));
    $this->artisan('privacy:retention-report', ['--school' => 'PRIV-BPL'])->assertSuccessful();
    $this->inSchool($this->tenant, function () {
        expect(RetentionRun::query()->where('kind', 'device_sessions')->where('status', 'dry_run')->firstOrFail()->count)->toBe(1)
            ->and(DeviceSession::query()->where('name', 'old phone')->exists())->toBeTrue()
            ->and(Notification::query()->where('type', 'privacy.retention')->exists())->toBeTrue();
    });
});

it('shows the grievance contact on the school profile and keeps an incident log with the 72-hour deadline', function () {
    $this->inSchool($this->tenant, function () {
        $current = app(SchoolConfigService::class)->current();
        $body = $current->body;
        $body['school']['grievance'] = ['name' => 'Anita Desai', 'role' => 'Grievance officer', 'email' => 'privacy@sunrise.test', 'phone' => '+910000000000'];
        app(SchoolConfigService::class)->save($this->freshUser($this->tenant, $this->chief), $body, (int) $current->version, 'Grievance contact');
    });
    $this->getJson('/api/v1/public/tenants/PRIV-BPL/config')->assertOk()->assertJsonPath('data.grievance.email', 'privacy@sunrise.test');
    $this->actingWithToken($this->parent)->getJson('/api/v1/privacy')->assertOk()->assertJsonPath('data.grievance.name', 'Anita Desai');

    $id = $this->actingWithToken($this->chief)->postJson('/api/v1/privacy/incidents', [
        'title' => 'Lost register photo phone', 'detected_at' => '2027-01-06T09:00:00+05:30', 'severity' => 'medium',
        'description' => 'A teacher lost a phone with photos of the 7-B register.', 'data_affected' => ['attendance'], 'people_affected' => 40,
    ])->assertCreated()->assertJsonPath('data.board_overdue', true)->json('data.id');
    $this->actingWithToken($this->chief)->postJson("/api/v1/privacy/incidents/{$id}/entries", ['note' => 'Reported to the Data Protection Board.', 'board_notified' => true, 'status' => 'contained'])->assertOk()
        ->assertJsonPath('data.board_overdue', false)->assertJsonPath('data.status', 'contained')->assertJsonCount(2, 'data.timeline');
    $this->actingWithToken($this->clerk)->getJson('/api/v1/privacy/incidents')->assertForbidden();
});
