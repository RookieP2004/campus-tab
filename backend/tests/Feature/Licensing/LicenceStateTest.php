<?php

use App\Modules\Licensing\Models\Licence;
use App\Modules\Tenancy\Domain\SchoolConfigService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\CreatesSchools;
use Tests\Concerns\RefreshMongoDatabase;

uses(RefreshMongoDatabase::class, CreatesSchools::class);

/*
| Item 2: expiry grace, then read-only; disabled modules return
| 403 module_disabled. Probe routes stand in for later modules' routes.
*/

beforeEach(function () {
    Route::middleware(['auth:sanctum', 'tenant', 'mfa.enrolled', 'licence'])->prefix('api/v1/_probe')->group(function () {
        Route::get('/', fn () => ['ok' => true]);
        Route::post('/', fn () => ['ok' => true]);
        Route::post('export', fn () => ['ok' => true])->defaults('licence_export', true);
        Route::get('fees', fn () => ['ok' => true])->middleware('module:fees');
    });
});

function schoolWithTerm(object $test, string $validTo, array $terms = []): array
{
    return $test->activeSchool('TERM-'.strtoupper(substr(md5($validTo.json_encode($terms)), 0, 6)), array_merge([
        'valid_from' => now('Asia/Kolkata')->subYears(2)->toDateString(),
        'valid_to' => $validTo,
    ], $terms));
}

it('allows everything while the licence is active', function () {
    $school = schoolWithTerm($this, now('Asia/Kolkata')->addMonth()->toDateString());

    $this->actingWithToken($school['chief'])->getJson('/api/v1/_probe')->assertOk()->assertHeader('X-Licence-State', 'active');
    $this->actingWithToken($school['chief'])->postJson('/api/v1/_probe')->assertOk();
});

it('keeps working with a banner during the grace days', function () {
    $school = schoolWithTerm($this, now('Asia/Kolkata')->subDays(5)->toDateString());

    $this->actingWithToken($school['chief'])->getJson('/api/v1/_probe')->assertOk()->assertHeader('X-Licence-State', 'grace');
    $this->actingWithToken($school['chief'])->postJson('/api/v1/_probe')->assertOk()->assertHeader('X-Licence-State', 'grace');
});

it('turns read-only after the grace days: reads and exports work, writes are refused', function () {
    $school = schoolWithTerm($this, now('Asia/Kolkata')->subDays(16)->toDateString());

    $this->actingWithToken($school['chief'])->getJson('/api/v1/_probe')->assertOk()->assertHeader('X-Licence-State', 'read_only');
    $this->actingWithToken($school['chief'])->postJson('/api/v1/_probe/export')->assertOk();
    $this->actingWithToken($school['chief'])->postJson('/api/v1/_probe')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'licence_read_only');
});

it('makes the school settings read-only too when the licence has expired', function () {
    $school = schoolWithTerm($this, now('Asia/Kolkata')->subDays(30)->toDateString());

    $this->actingWithToken($school['chief'])->getJson('/api/v1/config')->assertOk();
    $this->actingWithToken($school['chief'])->putJson('/api/v1/config', ['body' => []], ['If-Match' => '"v1"'])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'licence_read_only');
});

it('refuses everything when the licence is revoked', function () {
    $school = $this->activeSchool();
    $school['licence']->forceFill(['status' => Licence::STATUS_REVOKED])->save();

    $this->actingWithToken($school['chief'])->getJson('/api/v1/_probe')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'licence_inactive');
});

it('refuses everything when the licence record was tampered with', function () {
    $school = $this->activeSchool();
    Licence::query()->where('_id', $school['licence']->getKey())->update(['valid_to' => '2099-12-31']);

    $this->actingWithToken($school['chief'])->getJson('/api/v1/_probe')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'licence_inactive');
});

it('returns 403 module_disabled for a module the licence does not include', function () {
    $school = $this->activeSchool('NOFEES-BPL', ['modules' => ['attendance', 'leave']]);

    $this->actingWithToken($school['chief'])->getJson('/api/v1/_probe/fees')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'module_disabled')
        ->assertJsonPath('error.details.module', 'fees');
});

it('returns 403 module_disabled for a licensed module the school switched off', function () {
    $school = $this->activeSchool();
    $this->actingWithToken($school['chief'])->getJson('/api/v1/_probe/fees')->assertOk();

    $body = $this->configBody($school['tenant']);
    $body['modules']['fees'] = false;
    app(TenantContext::class)->run($school['tenant']->id, fn () => app(SchoolConfigService::class)->save($school['chief'], $body, 1));

    $this->actingWithToken($school['chief'])->getJson('/api/v1/_probe/fees')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'module_disabled');
});
