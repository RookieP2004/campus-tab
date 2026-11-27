<?php

use App\Modules\Licensing\Domain\LicenceKey;
use App\Modules\Licensing\Domain\LicenceSigner;
use App\Modules\Licensing\Models\Licence;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesSchools;
use Tests\Concerns\RefreshMongoDatabase;

uses(RefreshMongoDatabase::class, CreatesSchools::class);

/*
| Item 2: the key format, hash-only storage and the Ed25519 signature.
*/

it('generates keys in the CTAB-XXXX-XXXX-XXXX-XXXX format without look-alike characters', function () {
    foreach (range(1, 50) as $_) {
        expect(LicenceKey::generate())->toMatch('/^CTAB(-[2-9A-HJ-NP-Z]{4}){4}$/');
    }
});

it('accepts a key typed with lower case, spaces or no dashes', function (string $typed) {
    expect(LicenceKey::normalise($typed))->toBe('CTAB-7F3K-92QD-LX4M-8ZTA');
})->with([
    'canonical' => 'CTAB-7F3K-92QD-LX4M-8ZTA',
    'lower case' => 'ctab-7f3k-92qd-lx4m-8zta',
    'spaces' => ' CTAB 7F3K 92QD LX4M 8ZTA ',
    'no dashes' => 'CTAB7F3K92QDLX4M8ZTA',
    'without prefix' => '7F3K-92QD-LX4M-8ZTA',
]);

it('rejects text that cannot be a key', function (string $typed) {
    expect(LicenceKey::normalise($typed))->toBeNull();
})->with([
    'too short' => 'CTAB-7F3K-92QD-LX4M',
    'contains O' => 'CTAB-7F3K-92QD-LX4M-8ZTO',
    'contains 0' => 'CTAB-7F3K-92QD-LX4M-8ZT0',
    'contains I' => 'CTAB-7F3K-92QD-LX4M-8ZTI',
    'contains 1' => 'CTAB-7F3K-92QD-LX4M-8ZT1',
    'symbols' => 'CTAB-7F3K-92QD-LX4M-8Z$A',
]);

it('stores only a hash and the last four characters of the key', function () {
    ['licence' => $licence, 'key' => $key] = $this->pendingSchool();

    $raw = json_encode(DB::table('licences')->where('_id', $licence->getKey())->first());

    expect($raw)->not->toContain($key)
        ->and($raw)->not->toContain(substr($key, 5))
        ->and($licence->key_hash)->toBe(LicenceKey::hash($key))
        ->and($licence->key_last4)->toBe(substr($key, -4));
});

it('verifies a signed payload and rejects a changed one', function () {
    $signer = app(LicenceSigner::class);
    $signed = $signer->sign(['tenant_id' => 't_1', 'modules' => ['fees'], 'valid_to' => '2027-03-31']);

    expect($signer->verify($signed['payload'], $signed['signature']))->toBeTrue()
        ->and($signer->verify(str_replace('2027', '2099', $signed['payload']), $signed['signature']))->toBeFalse()
        ->and($signer->verify($signed['payload'], base64_encode(random_bytes(64))))->toBeFalse();
});

it('treats a licence edited in the database as invalid', function (array $change) {
    ['licence' => $licence] = $this->activeSchool();
    expect($licence->verifiedPayload())->not->toBeNull();

    DB::table('licences')->where('_id', $licence->getKey())->update($change);

    expect(Licence::query()->find($licence->getKey())->verifiedPayload())->toBeNull();
})->with([
    'longer term' => [['valid_to' => '2099-12-31']],
    'more modules' => [['modules' => ['attendance', 'leave', 'assessment', 'reports', 'fees', 'communication', 'extra']]],
    'more seats' => [['seats' => ['students' => 99999, 'staff' => 999]]],
    'forged payload' => [['payload' => '{"tenant_id":"t_1","valid_to":"2099-12-31"}']],
]);

it('signs payloads with the key pair from the environment', function () {
    config(['licensing.signing.secret_key' => null]);

    app(LicenceSigner::class)->sign(['a' => 1]);
})->throws(RuntimeException::class, 'LICENCE_SIGNING_SECRET_KEY');
