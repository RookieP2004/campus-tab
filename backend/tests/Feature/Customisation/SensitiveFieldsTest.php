<?php

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Domain\Role;
use App\Modules\People\Models\Student;
use App\Support\Privacy\SensitiveValue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsSchoolRecords;
use Tests\Concerns\CreatesSchools;
use Tests\Concerns\RefreshMongoDatabase;

uses(RefreshMongoDatabase::class, CreatesSchools::class, BuildsSchoolRecords::class);

/*
| Sensitive custom fields are encrypted at rest and masked on screen
| ("Customisation engine": "Fields marked sensitive are encrypted and masked").
*/

beforeEach(function () {
    $this->school = $this->activeSchool('SECRET-BPL');
    $this->tenant = $this->school['tenant'];
    $this->sections = $this->makeClass($this->tenant, '7', ['A']);
    $this->student = $this->enrol($this->tenant, $this->sections['A'], 'Aarav Patel');
    $this->actingWithToken($this->school['chief'])->postJson('/api/v1/custom-fields', [
        'entity' => 'student', 'label' => ['en' => 'Medical notes'], 'type' => 'long_text', 'sensitive' => true, 'scope' => ['level' => 'school'],
    ])->assertCreated();
});

function rawCustom(object $test): mixed
{
    return DB::table('students')->where('tenant_id', $test->tenant->id)->where('_id', $test->student->getKey())->first()->custom['medical_notes'] ?? null;
}

it('stores sensitive values encrypted, and keeps them out of the audit log in plain text', function () {
    $this->actingWithToken($this->school['chief'])->patchJson('/api/v1/students/'.$this->student->getKey(), ['custom' => ['medical_notes' => 'Made up: mild asthma']])->assertOk();

    $raw = rawCustom($this);
    expect(SensitiveValue::isSealed((array) $raw))->toBeTrue()
        ->and(json_encode($raw))->not->toContain('asthma');

    $this->inSchool($this->tenant, fn () => expect(json_encode(AuditLog::query()->get()->toArray()))->not->toContain('asthma'));
});

it('masks the value on screen and decrypts it only for someone who may fill the field', function () {
    $this->actingWithToken($this->school['chief'])->patchJson('/api/v1/students/'.$this->student->getKey(), ['custom' => ['medical_notes' => 'Made up: mild asthma']])->assertOk();

    $this->actingWithToken($this->school['chief'])->getJson('/api/v1/students')->assertJsonPath('data.0.custom.medical_notes', '••••');
    $this->actingWithToken($this->school['chief'])->getJson('/api/v1/students/'.$this->student->getKey())
        ->assertJsonPath('data.custom.medical_notes', '••••')
        ->assertJsonPath('data.sensitive_values.medical_notes', 'Made up: mild asthma');

    // A class teacher sees the field (school-wide) but cannot fill it: mask only.
    $teacher = $this->staffMember($this->tenant, Role::ClassTeacher);
    $this->makeClassTeacher($this->tenant, $this->sections['A'], $teacher['staff']);
    $this->actingWithToken($teacher['user'])->getJson('/api/v1/students/'.$this->student->getKey())
        ->assertJsonPath('data.custom.medical_notes', '••••')
        ->assertJsonMissingPath('data.sensitive_values.medical_notes');
});

it('encrypts plain values left from before, once', function () {
    $this->inSchool($this->tenant, fn () => Student::query()->findOrFail($this->student->getKey())->forceFill(['custom' => ['medical_notes' => 'Made up: plain']])->save());
    expect(rawCustom($this))->toBe('Made up: plain');

    Artisan::call('customisation:seal-sensitive');
    Artisan::call('customisation:seal-sensitive');

    expect(SensitiveValue::open((array) rawCustom($this)))->toBe('Made up: plain');
});
