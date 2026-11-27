<?php

use App\Modules\Identity\Models\User;
use App\Modules\People\Models\Staff;
use App\Modules\People\Models\Student;
use App\Modules\Tenancy\Models\Tenant;
use Database\Seeders\DemoSchoolSeeder;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\CreatesSchools;
use Tests\Concerns\RefreshMongoDatabase;

uses(RefreshMongoDatabase::class, CreatesSchools::class);

/*
| Phase 2 item 7 and rule 8: the demo school loads, and its one known
| development password exists only in local and testing.
*/

it('loads Sunrise Public School with staff, classes, students and a parent of two', function () {
    expect(Artisan::call('db:seed', ['--class' => DemoSchoolSeeder::class, '--force' => true]))->toBe(0);

    $tenant = Tenant::findByCode('SUNRISE-BPL');
    expect($tenant?->status)->toBe('active');

    $this->inSchool($tenant, function () {
        expect(Student::query()->count())->toBe(57)
            ->and(Staff::query()->count())->toBe(11)
            ->and(User::query()->where('email', 'parent@sunrise.test')->first()?->roles)->toBe(['parent']);
    });

    // Every role can sign in with the dev password (roles from Clerk up then set up MFA).
    foreach (['principal@sunrise.test' => true, 'anjali.verma@sunrise.test' => false, 'parent@sunrise.test' => false, 'sps20260002' => false] as $login => $enrol) {
        app('auth')->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['school_code' => 'SUNRISE-BPL', 'login' => $login, 'password' => DemoSchoolSeeder::DEMO_PASSWORD])
            ->assertOk()->assertJsonPath('data.mfa_enrolment_required', $enrol);
    }

    // Running it again changes nothing.
    Artisan::call('db:seed', ['--class' => DemoSchoolSeeder::class, '--force' => true]);
    $this->inSchool($tenant, fn () => expect(Student::query()->count())->toBe(57));
});

it('refuses to run anywhere but local and testing', function () {
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => (new DemoSchoolSeeder)->run())->toThrow(RuntimeException::class, 'runs only when APP_ENV is local or testing');
    expect(Tenant::findByCode('SUNRISE-BPL'))->toBeNull();
});
