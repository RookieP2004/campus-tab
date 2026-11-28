<?php

use App\Modules\Communication\Jobs\SendOutsideNotifications;
use App\Modules\Communication\Models\PushDevice;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Domain\SessionIssuer;
use App\Support\Push\ApnsPushSender;
use App\Support\Push\ArrayPushSender;
use App\Support\Push\FcmPushSender;
use App\Support\Push\Jwt;
use App\Support\Push\NativePushSender;
use App\Support\Push\PushSender;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsSchoolRecords;
use Tests\Concerns\CreatesSchools;
use Tests\Concerns\RefreshMongoDatabase;

uses(RefreshMongoDatabase::class, CreatesSchools::class, BuildsSchoolRecords::class);

/*
| Phase 9 apps: push through FCM (Android) and APNs (iOS) with the same
| generic texts; the device stays registered after the app closes (the
| owner's choice) until a sign-out on purpose, a remote sign-out, an
| account-wide reset or 30 days without a sign-in; the app origins may call
| the API with a bearer token but never with cookies.
*/

beforeEach(function () {
    $this->school = $this->activeSchool('PUSH-BPL');
    $this->tenant = $this->school['tenant'];
    $this->parent = $this->schoolUser($this->tenant, Role::Parent, false);
});

function registerPhone(object $test, string $token, string $platform = 'android'): string
{
    $bearer = $test->tokenFor($test->parent);
    app('auth')->forgetGuards();
    $test->withToken($bearer)->postJson('/api/v1/me/push-devices', ['token' => $token, 'platform' => $platform])->assertCreated();

    return $bearer;
}

function pushDevices(object $test): int
{
    return $test->inSchool($test->tenant, fn () => PushDevice::query()->count());
}

it('keeps push after the app closes, and stops it on a sign-out on purpose', function () {
    $bearer = registerPhone($this, str_repeat('a', 40));
    $device = $this->inSchool($this->tenant, fn () => PushDevice::query()->firstOrFail());
    expect($device->device_session_id)->not->toBeNull()->and($device->last_seen_at)->not->toBeNull();

    // Closing the app ends the sign-in on the server, but push stays.
    app('auth')->forgetGuards();
    $this->withToken($bearer)->postJson('/api/v1/auth/logout', ['closing' => true])->assertOk();
    expect(pushDevices($this))->toBe(1);
    app('auth')->forgetGuards();
    $this->withToken($bearer)->getJson('/api/v1/me')->assertUnauthorized();

    // The next sign-in re-registers the same phone; signing out on purpose stops push.
    $bearer = registerPhone($this, str_repeat('a', 40));
    expect(pushDevices($this))->toBe(1);
    app('auth')->forgetGuards();
    $this->withToken($bearer)->postJson('/api/v1/auth/logout')->assertOk();
    expect(pushDevices($this))->toBe(0);
});

it('stops push when the device is signed out remotely or the account is reset', function () {
    registerPhone($this, str_repeat('b', 40));
    $session = $this->inSchool($this->tenant, fn () => PushDevice::query()->firstOrFail()->device_session_id);

    // Signed out from another device's session list.
    $this->actingWithToken($this->parent)->deleteJson("/api/v1/me/sessions/{$session}")->assertOk();
    expect(pushDevices($this))->toBe(0);

    // An account-wide reason (deactivated, password changed) forgets every device.
    registerPhone($this, str_repeat('c', 40));
    registerPhone($this, str_repeat('d', 40), 'ios');
    $this->inSchool($this->tenant, fn () => app(SessionIssuer::class)->revokeAllFor($this->freshUser($this->tenant, $this->parent), 'password_changed'));
    expect(pushDevices($this))->toBe(0);
});

it('forgets a device after 30 days without a sign-in', function () {
    registerPhone($this, str_repeat('e', 40));
    registerPhone($this, str_repeat('f', 40));
    $this->inSchool($this->tenant, fn () => PushDevice::query()->get()->first()->forceFill(['last_seen_at' => now()->subDays(31)])->save());

    $this->artisan('push:forget-stale')->assertSuccessful();
    expect(pushDevices($this))->toBe(1);
});

it('sends the generic text to each platform and forgets tokens the service says are gone', function () {
    registerPhone($this, str_repeat('1', 40));
    registerPhone($this, str_repeat('2', 64), 'ios');
    /** @var ArrayPushSender $push */
    $push = app(PushSender::class);
    $push->gone = [str_repeat('1', 40)];

    (new SendOutsideNotifications((string) $this->tenant->getKey(), [(string) $this->parent->getKey()], 'notice'))->handle(app(TenantContext::class), $push);

    expect($push->sent)->toHaveCount(1)
        ->and(collect($push->sent[0]['devices'])->pluck('platform')->sort()->values()->all())->toBe(['android', 'ios'])
        ->and($push->sent[0]['body'])->toStartWith('New notice from');
    expect(pushDevices($this))->toBe(1);
});

it('sends Android push through FCM HTTP v1 with the service account', function () {
    Cache::flush();
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600]),
        'fcm.googleapis.com/v1/projects/campus-tab-test/messages:send' => Http::sequence()
            ->push(['name' => 'projects/x/messages/1'])
            ->push(['error' => ['status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]], 404),
    ]);
    $fcm = new FcmPushSender(['project_id' => 'campus-tab-test', 'client_email' => 'push@campus-tab-test.iam.example', 'private_key' => $pem]);

    $gone = $fcm->send([['token' => 'tok-live', 'platform' => 'android'], ['token' => 'tok-gone', 'platform' => 'android']], 'Campus TAB', 'New notice');

    expect($gone)->toBe(['tok-gone']);
    Http::assertSent(function (HttpRequest $r) use ($key) {
        if (! str_contains($r->url(), 'oauth2')) {
            return false;
        }
        [$h, $c, $s] = explode('.', (string) $r['assertion']);
        $claims = json_decode(base64_decode(strtr($c, '-_', '+/')), true);

        return $claims['scope'] === 'https://www.googleapis.com/auth/firebase.messaging'
            && openssl_verify("{$h}.{$c}", base64_decode(strtr($s, '-_', '+/')), openssl_pkey_get_details($key)['key'], OPENSSL_ALGO_SHA256) === 1;
    });
    Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'messages:send') && $r->hasHeader('Authorization', 'Bearer ya29.test')
        && $r['message']['notification'] === ['title' => 'Campus TAB', 'body' => 'New notice'] && ! isset($r['message']['data']));
});

it('sends iPhone push through APNs over HTTP/2 with an ES256 provider token', function () {
    Cache::flush();
    $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    openssl_pkey_export($key, $pem);
    Http::fake([
        'api.sandbox.push.apple.com/3/device/'.str_repeat('a', 64) => Http::response('', 200),
        'api.sandbox.push.apple.com/3/device/'.str_repeat('b', 64) => Http::response(['reason' => 'Unregistered'], 410),
    ]);
    $apns = new ApnsPushSender('KEY123', 'TEAM456', 'in.campustab.app', $pem, true);

    $gone = $apns->send([['token' => str_repeat('a', 64), 'platform' => 'ios'], ['token' => str_repeat('b', 64), 'platform' => 'ios'], ['token' => 'not-hex', 'platform' => 'ios']], 'Campus TAB', 'New notice');

    expect($gone)->toBe([str_repeat('b', 64), 'not-hex']);
    Http::assertSent(function (HttpRequest $r) {
        $jwt = substr($r->header('authorization')[0], 7);
        [$h] = explode('.', $jwt);

        return $r->hasHeader('apns-topic', 'in.campustab.app') && $r->hasHeader('apns-push-type', 'alert')
            && json_decode(base64_decode(strtr($h, '-_', '+/')), true) === ['alg' => 'ES256', 'kid' => 'KEY123']
            && strlen(base64_decode(strtr(explode('.', $jwt)[2], '-_', '+/'))) === 64
            && $r['aps']['alert'] === ['title' => 'Campus TAB', 'body' => 'New notice'];
    });
});

it('routes Android to FCM and iPhones to APNs, skipping a side with no keys', function () {
    $android = new ArrayPushSender;
    $native = new NativePushSender($android, null);
    $native->send([['token' => 'a1', 'platform' => 'android'], ['token' => 'i1', 'platform' => 'ios']], 'Campus TAB', 'x');

    expect($android->sent)->toHaveCount(1)->and($android->sent[0]['devices'])->toBe([['token' => 'a1', 'platform' => 'android']]);
    expect(strlen(Jwt::derToRaw("\x30\x06\x02\x01\x05\x02\x01\x07", 32)))->toBe(64);
});

it('lets the app origins call the API with a bearer token, never with cookies', function () {
    $this->call('OPTIONS', '/api/v1/me', [], [], [], ['HTTP_ORIGIN' => 'https://localhost', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET', 'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization'])
        ->assertNoContent()
        ->assertHeader('Access-Control-Allow-Origin', 'https://localhost')
        ->assertHeaderMissing('Access-Control-Allow-Credentials');

    $this->actingWithToken($this->parent)->withHeader('Origin', 'http://tauri.localhost')->getJson('/api/v1/me')
        ->assertOk()->assertHeader('Access-Control-Allow-Origin', 'http://tauri.localhost')->assertHeaderMissing('Access-Control-Allow-Credentials');

    // Any other origin is never allowed (Laravel answers with the web app's own origin only).
    $response = $this->call('OPTIONS', '/api/v1/me', [], [], [], ['HTTP_ORIGIN' => 'https://evil.example', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET']);
    expect($response->headers->get('Access-Control-Allow-Origin'))->not->toBe('https://evil.example');
});
