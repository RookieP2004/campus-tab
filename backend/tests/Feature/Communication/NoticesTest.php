<?php

use App\Modules\Communication\Mail\NotificationMail;
use App\Modules\Communication\Models\Notification;
use App\Modules\Identity\Domain\Role;
use App\Modules\People\Domain\ParentLogins;
use App\Support\Push\PushSender;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsSchoolRecords;
use Tests\Concerns\CreatesSchools;
use Tests\Concerns\RefreshMongoDatabase;

uses(RefreshMongoDatabase::class, CreatesSchools::class, BuildsSchoolRecords::class);

/*
| Phase 3 items 4 and 5, rules 3 and 4: notices with audiences, attachments
| behind short-lived signed links, read receipts, and notifications whose
| push and email carry no private details.
*/

beforeEach(function () {
    Storage::fake('local');
    Mail::fake();
    $this->school = $this->activeSchool('NOTICE-BPL');
    $this->tenant = $this->school['tenant'];
    $this->chief = $this->school['chief'];
    $this->s7 = $this->makeClass($this->tenant, '7', ['A', 'B']);
    $this->teacher = $this->staffMember($this->tenant, Role::ClassTeacher);
    $this->makeClassTeacher($this->tenant, $this->s7['B'], $this->teacher['staff']);
    $this->aarav = $this->enrol($this->tenant, $this->s7['B'], 'Aarav Patel', '+919811110001');
    $this->diya = $this->enrol($this->tenant, $this->s7['A'], 'Diya Gupta', '+919811110002');
    $this->parentB = $this->inSchool($this->tenant, fn () => app(ParentLogins::class)->enable($this->chief, $this->guardianOf($this->tenant, $this->aarav)));
    $this->parentA = $this->inSchool($this->tenant, fn () => app(ParentLogins::class)->enable($this->chief, $this->guardianOf($this->tenant, $this->diya)));
});

function noticeTitles(object $test, $user): array
{
    return collect($test->actingWithToken($user)->getJson('/api/v1/notices')->assertOk()->json('data'))->pluck('title')->all();
}

it('lets a class teacher send only to their own section, and reaches only that section', function () {
    $this->actingWithToken($this->teacher['user'])->postJson('/api/v1/notices', ['title' => 'Whole school', 'body' => 'x', 'audience' => ['type' => 'school']])
        ->assertForbidden()->assertJsonPath('error.code', 'notice_audience_forbidden');
    $this->actingWithToken($this->teacher['user'])->postJson('/api/v1/notices', ['title' => 'Other class', 'body' => 'x', 'audience' => ['type' => 'section', 'refs' => [(string) $this->s7['A']->getKey()]]])
        ->assertForbidden();

    $this->actingWithToken($this->teacher['user'])->postJson('/api/v1/notices', ['title' => 'Bring a raincoat', 'body' => 'Field trip tomorrow.', 'audience' => ['type' => 'section', 'refs' => [(string) $this->s7['B']->getKey()]]])
        ->assertCreated()->assertJsonPath('data.recipient_count', 2);

    expect(noticeTitles($this, $this->parentB))->toBe(['Bring a raincoat'])
        ->and(noticeTitles($this, $this->parentA))->toBe([])
        ->and(noticeTitles($this, $this->chief))->toBe(['Bring a raincoat']);
});

it('reaches everyone for a school notice and the named roles for a role notice', function () {
    $this->actingWithToken($this->chief)->postJson('/api/v1/notices', ['title' => 'Holiday on Friday', 'body' => 'x', 'audience' => ['type' => 'school']])->assertCreated();
    $this->actingWithToken($this->chief)->postJson('/api/v1/notices', ['title' => 'Staff meeting', 'body' => 'x', 'audience' => ['type' => 'role', 'refs' => ['class_teacher']]])->assertCreated();

    expect(noticeTitles($this, $this->parentA))->toBe(['Holiday on Friday'])
        ->and(noticeTitles($this, $this->teacher['user']))->toBe(['Staff meeting', 'Holiday on Friday']);
});

it('keeps read receipts, visible to the sender and the office only', function () {
    $id = $this->actingWithToken($this->teacher['user'])->postJson('/api/v1/notices', ['title' => 'Bring a raincoat', 'body' => 'x', 'audience' => ['type' => 'section', 'refs' => [(string) $this->s7['B']->getKey()]]])->json('data.id');

    $this->actingWithToken($this->parentB)->getJson("/api/v1/notices/{$id}")->assertOk()->assertJsonPath('data.read', true);
    $this->actingWithToken($this->parentA)->getJson("/api/v1/notices/{$id}")->assertNotFound();

    $this->actingWithToken($this->teacher['user'])->getJson("/api/v1/notices/{$id}/receipts")
        ->assertOk()->assertJsonPath('data.read_count', 1)->assertJsonPath('data.recipient_count', 2)->assertJsonPath('data.readers.0.name', $this->parentB->name);
    $this->actingWithToken($this->parentB)->getJson("/api/v1/notices/{$id}/receipts")->assertForbidden();
});

it('limits attachments by type and size, keeps them in the school\'s folder and serves them only by a short-lived signed link', function () {
    $pdf = UploadedFile::fake()->create('trip-form.pdf', 100, 'application/pdf');
    $exe = UploadedFile::fake()->create('virus.exe', 10, 'application/octet-stream');
    $huge = UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf');

    $this->actingWithToken($this->chief)->post('/api/v1/notices', ['title' => 'Bad', 'body' => 'x', 'audience' => ['type' => 'school'], 'attachments' => [$exe]], ['Accept' => 'application/json'])->assertStatus(422);
    $this->actingWithToken($this->chief)->post('/api/v1/notices', ['title' => 'Bad', 'body' => 'x', 'audience' => ['type' => 'school'], 'attachments' => [$huge]], ['Accept' => 'application/json'])->assertStatus(422);

    $notice = $this->actingWithToken($this->chief)->post('/api/v1/notices', ['title' => 'Trip form', 'body' => 'x', 'audience' => ['type' => 'section', 'refs' => [(string) $this->s7['B']->getKey()]], 'attachments' => [$pdf]], ['Accept' => 'application/json'])
        ->assertCreated()->json('data');
    $attachment = $notice['attachments'][0]['id'];

    expect(Storage::disk('local')->allFiles('tenants/'.$this->tenant->id.'/notices'))->toHaveCount(1);

    // Someone outside the audience gets no link.
    $this->actingWithToken($this->parentA)->postJson("/api/v1/notices/{$notice['id']}/attachments/{$attachment}/link")->assertNotFound();

    $url = $this->actingWithToken($this->parentB)->postJson("/api/v1/notices/{$notice['id']}/attachments/{$attachment}/link")->assertOk()->json('data.url');
    app('auth')->forgetGuards();
    $this->get($url)->assertOk()->assertDownload('trip-form.pdf');

    // A tampered or expired link is refused.
    $this->get(str_replace('signature=', 'signature=x', $url))->assertForbidden();
    $this->travel(6)->minutes();
    $this->get($url)->assertForbidden();
});

it('notifies in the app with the title, but push and email say nothing private', function () {
    $this->actingWithToken($this->parentB)->postJson('/api/v1/me/push-devices', ['token' => str_repeat('a', 40), 'platform' => 'android'])->assertCreated();
    $this->actingWithToken($this->parentB)->putJson('/api/v1/me/notification-preferences', ['email' => true])->assertOk();
    $this->inSchool($this->tenant, fn () => $this->parentB->forceFill(['email' => 'parent@notice-bpl.test'])->save());

    $this->actingWithToken($this->teacher['user'])->postJson('/api/v1/notices', ['title' => 'Aarav needs a raincoat', 'body' => 'Private details', 'audience' => ['type' => 'section', 'refs' => [(string) $this->s7['B']->getKey()]]])->assertCreated();

    $this->actingWithToken($this->parentB)->getJson('/api/v1/me/notifications')->assertOk()
        ->assertJsonPath('unread', 1)->assertJsonPath('data.0.title', 'Aarav needs a raincoat');

    $push = app(PushSender::class)->sent;
    expect($push)->toHaveCount(1)
        ->and($push[0]['body'])->toBe('New notice from Notice Bpl School. Open Campus TAB to read it.')
        ->and(json_encode($push))->not->toContain('Aarav')->not->toContain('raincoat');

    Mail::assertSent(NotificationMail::class, fn ($m) => $m->hasTo('parent@notice-bpl.test') && ! str_contains($m->text, 'Aarav'));

    // Muting a type stops push and email for it.
    $this->actingWithToken($this->parentB)->putJson('/api/v1/me/notification-preferences', ['muted' => ['notice']])->assertOk()->assertJsonPath('data.muted', ['notice']);
    $this->actingWithToken($this->chief)->postJson('/api/v1/notices', ['title' => 'Second', 'body' => 'x', 'audience' => ['type' => 'school']])->assertCreated();
    expect(app(PushSender::class)->sent)->toHaveCount(1);

    $this->actingWithToken($this->parentB)->postJson('/api/v1/me/notifications/read-all')->assertOk();
    expect($this->inSchool($this->tenant, fn () => Notification::query()->where('user_id', (string) $this->parentB->getKey())->whereNull('read_at')->count()))->toBe(0);
});

it('authorises the Reverb channel only for the user\'s own channel', function () {
    $callback = Broadcast::driver()->getChannels()['school.{tenantId}.user.{userId}'];
    $parent = $this->freshUser($this->tenant, $this->parentB);

    expect($callback($parent, $this->tenant->id, (string) $parent->getKey()))->toBeTrue()
        ->and($callback($parent, $this->tenant->id, (string) $this->parentA->getKey()))->toBeFalse()
        ->and($callback($parent, 't_OTHERSCHOOL', (string) $parent->getKey()))->toBeFalse();

    // The auth route itself needs a signed-in session (it is in the account group).
    app('auth')->forgetGuards();
    $this->postJson('/api/v1/broadcasting/auth', ['socket_id' => '1.1', 'channel_name' => 'private-school.x.user.y'])->assertUnauthorized();
});
