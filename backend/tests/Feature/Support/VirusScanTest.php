<?php

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Communication\Models\Notice;
use App\Support\Uploads\ClamAvScanner;
use App\Support\Uploads\FakeVirusScanner;
use App\Support\Uploads\ScannerUnavailable;
use App\Support\Uploads\ScanUploadedFiles;
use App\Support\Uploads\VirusScanner;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsSchoolRecords;
use Tests\Concerns\CreatesSchools;
use Tests\Concerns\RefreshMongoDatabase;

uses(RefreshMongoDatabase::class, CreatesSchools::class, BuildsSchoolRecords::class);

/*
| Phase 9 security pass: every upload is virus-scanned before it is stored;
| an infected file is refused and logged; a scanner that is down refuses
| uploads (fail closed).
*/

beforeEach(function () {
    Storage::fake('local');
    $this->school = $this->activeSchool('SCAN-BPL', ['modules' => ['attendance', 'leave', 'reports', 'communication']]);
    $this->tenant = $this->school['tenant'];
    $this->chief = $this->school['chief'];
});

function eicar(string $name): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, FakeVirusScanner::EICAR);
}

it('refuses an infected notice attachment, leave document, letterhead and register photo, and logs each', function (string $url, string $field, string $name) {
    $id = '6b0000000000000000000001';
    $this->actingWithToken($this->chief)->post(str_replace('{id}', $id, $url), [$field => $field === 'attachments' ? [eicar($name)] : eicar($name), 'title' => 'x', 'body' => 'x'], ['Accept' => 'application/json'])
        ->assertStatus(422)->assertJsonPath('error.code', 'file_infected');

    $this->inSchool($this->tenant, function () use ($field) {
        $entry = AuditLog::query()->where('action', 'upload.infected')->firstOrFail();
        expect($entry->after['found'])->toBe('Eicar-Test-Signature')
            ->and($entry->after['field'])->toStartWith($field)
            ->and($entry->after['sha256'])->toBe(hash('sha256', FakeVirusScanner::EICAR))
            ->and(json_encode($entry->toArray()))->not->toContain('EICAR-STANDARD');
    });
    expect(Storage::disk('local')->allFiles())->toBe([]);
})->with([
    'notice attachment' => ['/api/v1/notices', 'attachments', 'circular.pdf'],
    'leave document' => ['/api/v1/leave-requests/{id}/documents', 'file', 'medical.pdf'],
    'letterhead' => ['/api/v1/report-templates/{id}/letterhead', 'file', 'letterhead.png'],
    'register photo' => ['/api/v1/attendance/days/{id}/photo', 'photo', 'register.jpg'],
]);

it('stores a clean attachment after scanning it, and stores nothing when it is infected', function () {
    $section = $this->makeClass($this->tenant, '7', ['A'])['A'];
    $body = ['title' => 'Sports day', 'body' => 'Details inside', 'audience' => ['type' => 'school']];

    $this->actingWithToken($this->chief)->post('/api/v1/notices', $body + ['attachments' => [eicar('plan.pdf')]], ['Accept' => 'application/json'])->assertStatus(422);
    expect($this->inSchool($this->tenant, fn () => Notice::query()->count()))->toBe(0);

    $clean = UploadedFile::fake()->createWithContent('plan.pdf', "%PDF-1.4\n%clean\n");
    $this->actingWithToken($this->chief)->post('/api/v1/notices', $body + ['attachments' => [$clean]], ['Accept' => 'application/json'])->assertCreated();
    expect(app(VirusScanner::class)->scanned)->toHaveCount(2)
        ->and($this->inSchool($this->tenant, fn () => Notice::query()->count()))->toBe(1);
    unset($section);
});

it('refuses uploads while the scanner is down, and keeps everything else working', function () {
    app(VirusScanner::class)->down = true;

    $this->actingWithToken($this->chief)->post('/api/v1/notices', ['title' => 'x', 'body' => 'x', 'audience' => ['type' => 'school'], 'attachments' => [UploadedFile::fake()->createWithContent('a.pdf', '%PDF-1.4')]], ['Accept' => 'application/json'])
        ->assertStatus(503)->assertJsonPath('error.code', 'virus_scan_unavailable');
    $this->actingWithToken($this->chief)->postJson('/api/v1/notices', ['title' => 'No file', 'body' => 'Still works', 'audience' => ['type' => 'school']])->assertCreated();
});

it('puts every route that reads an uploaded file behind the scanner', function () {
    $router = app('router');
    $checked = 0;
    foreach ($router->getRoutes()->getRoutes() as $route) {
        /** @var Route $route */
        $action = $route->getActionName();
        if (! str_contains($action, '@')) {
            continue;
        }
        [$class, $method] = explode('@', $action);
        if (! method_exists($class, $method)) {
            continue;
        }
        $ref = new ReflectionMethod($class, $method);
        $source = implode('', array_slice(file((string) $ref->getFileName()) ?: [], $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1));
        // Reading an upload ($request->file(), allFiles(), UploadedFile), not sending one (response()->file()).
        if (! preg_match('/\$request->file\(|allFiles\(|UploadedFile/', $source)) {
            continue;
        }
        $checked++;
        expect(in_array(ScanUploadedFiles::class, $router->gatherRouteMiddleware($route), true))->toBeTrue("{$route->uri()} takes a file but is not scanned");
    }
    expect($checked)->toBeGreaterThanOrEqual(8);
});

it('speaks the clamd INSTREAM protocol', function () {
    $host = (string) env('CLAMAV_TEST_HOST', '');
    if ($host === '') {
        $this->markTestSkipped('Set CLAMAV_TEST_HOST to run against a real ClamAV (the local stack: clamav).');
    }
    $scanner = new ClamAvScanner($host, 3310, 10);
    $bad = tempnam(sys_get_temp_dir(), 'eicar');
    file_put_contents($bad, FakeVirusScanner::EICAR);
    $good = tempnam(sys_get_temp_dir(), 'clean');
    file_put_contents($good, 'hello');

    expect($scanner->scan($bad))->toContain('Eicar')->and($scanner->scan($good))->toBeNull();
    expect(fn () => (new ClamAvScanner('127.0.0.1', 1, 1))->scan($good))->toThrow(ScannerUnavailable::class);
});
