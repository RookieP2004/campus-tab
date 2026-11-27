<?php

use App\Modules\Identity\Models\SchoolRole;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\CreatesSchools;
use Tests\Concerns\RefreshMongoDatabase;

uses(RefreshMongoDatabase::class, CreatesSchools::class);

it('gives existing schools the permissions a later phase added, but keeps what the Chief removed', function () {
    $school = $this->activeSchool('SYNC-BPL');

    // As if the school was set up before Phase 2: no academic.view, and the Chief removed students.edit from the Clerk.
    $this->inSchool($school['tenant'], function () {
        $teacher = SchoolRole::query()->where('name', 'class_teacher')->firstOrFail();
        $teacher->forceFill(['permissions' => array_values(array_diff($teacher->permissions, ['academic.view:school'])), 'seeded_permissions' => null])->save();

        $clerk = SchoolRole::query()->where('name', 'clerk')->firstOrFail();
        $clerk->forceFill(['permissions' => array_values(array_diff($clerk->permissions, ['students.edit:school']))])->save();
    });

    expect(Artisan::call('identity:sync-roles', ['code' => 'SYNC-BPL', '--with-new-permissions' => true]))->toBe(0);

    $this->inSchool($school['tenant'], function () {
        expect(SchoolRole::query()->where('name', 'class_teacher')->firstOrFail()->permissions)->toContain('academic.view:school')
            ->and(SchoolRole::query()->where('name', 'clerk')->firstOrFail()->permissions)->not->toContain('students.edit:school');
    });
});
