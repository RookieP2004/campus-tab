<?php

use App\Modules\Identity\Domain\Role;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesSchools;
use Tests\Concerns\RefreshMongoDatabase;

uses(RefreshMongoDatabase::class, CreatesSchools::class);

beforeEach(function () {
    Storage::fake('local');
    $this->school = $this->activeSchool('LOGO-BPL');
});

/** A tiny real PNG (1 x 1 pixel). */
function pngFile(): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'logo').'.png';
    file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));

    return new UploadedFile($path, 'logo.png', 'image/png', null, true);
}

it('lets the Chief upload the logo, which the public config and logo URL then serve', function () {
    $url = $this->actingWithToken($this->school['chief'])->post('/api/v1/config/logo', ['file' => pngFile()], ['Accept' => 'application/json'])
        ->assertCreated()->json('data.logo_url');

    expect($url)->toStartWith('/api/v1/public/tenants/LOGO-BPL/logo?v=');
    $this->getJson('/api/v1/public/tenants/logo-bpl/config')->assertJsonPath('data.logo_url', $url);
    $this->get('/api/v1/public/tenants/LOGO-BPL/logo')->assertOk()->assertHeader('Content-Type', 'image/png');
});

it('refuses SVG and anyone who may not edit the school profile', function () {
    $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
    $this->actingWithToken($this->school['chief'])->post('/api/v1/config/logo', ['file' => $svg], ['Accept' => 'application/json'])->assertStatus(422);

    $principal = $this->schoolUser($this->school['tenant'], Role::Principal);
    $this->actingWithToken($principal)->post('/api/v1/config/logo', ['file' => pngFile()], ['Accept' => 'application/json'])->assertForbidden();
});
