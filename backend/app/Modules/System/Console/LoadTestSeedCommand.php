<?php

namespace App\Modules\System\Console;

use App\Modules\AcademicStructure\Domain\Structure;
use App\Modules\AcademicStructure\Models\AcademicYear;
use App\Modules\AcademicStructure\Models\Section;
use App\Modules\AcademicStructure\Models\Subject;
use App\Modules\Assessment\Domain\Assessments;
use App\Modules\Assessment\Domain\PaperLifecycle;
use App\Modules\Assessment\Models\Paper;
use App\Modules\Fees\Domain\DemandRaiser;
use App\Modules\Fees\Models\FeeHead;
use App\Modules\Fees\Models\FeePlan;
use App\Modules\Fees\Models\StudentFee;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Licensing\Domain\LicenceIssuer;
use App\Modules\Licensing\Models\Licence;
use App\Modules\People\Models\Enrolment;
use App\Modules\People\Models\Guardian;
use App\Modules\People\Models\Staff;
use App\Modules\People\Models\Student;
use App\Modules\Tenancy\Domain\SchoolConfigService;
use App\Modules\Tenancy\Domain\TenantProvisioner;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

/**
 * Seeds the LOAD TEST database (Phase 9): N schools × M students, each with
 * classes 1–10 (four sections), a class teacher per section who also
 * teaches Mathematics, an open Maths class test per section, a fee
 * collector, and fee dues in the first few schools. Writes the accounts and
 * ids k6 needs to infra/loadtest/fixtures.json (git-ignored).
 *
 * Runs only against a database whose name ends in "_load" and never in
 * production: every account shares one made-up password. Students,
 * enrolments and guardians are inserted in bulk (no audit entries) because
 * 50,000 single writes would take hours; everything else goes through the
 * normal domain code.
 */
class LoadTestSeedCommand extends Command
{
    public const PASSWORD = 'loadtest-only-2026';

    protected $signature = 'loadtest:seed {--schools=50} {--students=1000} {--fee-schools=5}';

    protected $description = 'Seed the load-test database (campus_tab_load) with schools, students and k6 fixtures';

    public function handle(TenantContext $context): int
    {
        $db = (string) config('database.connections.mongodb.database');
        if (app()->environment('production') || ! str_ends_with($db, '_load')) {
            $this->error("Refusing: run with MONGODB_DATABASE=campus_tab_load (now {$db}) and never in production.");

            return self::FAILURE;
        }
        $schools = (int) $this->option('schools');
        $perSchool = (int) $this->option('students');
        $feeSchools = (int) $this->option('fee-schools');
        $started = microtime(true);
        // In Docker the api-load service mounts infra/loadtest at /loadtest.
        $path = (string) (getenv('LOADTEST_FIXTURES') ?: base_path('../infra/loadtest/fixtures.json'));

        for ($n = 1; $n <= $schools; $n++) {
            $code = sprintf('LOAD-%03d', $n);
            if (Tenant::query()->where('code', $code)->exists()) {
                $this->line("{$code} exists, skipped");

                continue;
            }
            $tenant = app(TenantProvisioner::class)->create(['code' => $code, 'name' => "Load Test School {$n}", 'email' => "office@load{$n}.test", 'phone' => '+91900000'.str_pad((string) $n, 4, '0', STR_PAD_LEFT)]);
            $issued = app(LicenceIssuer::class)->issue($tenant, [
                'plan' => 'standard', 'modules' => config('licensing.modules'), 'students' => $perSchool + 100, 'staff' => 120,
                'valid_from' => now('Asia/Kolkata')->subMonth()->toDateString(), 'valid_to' => now('Asia/Kolkata')->addYear()->toDateString(),
            ]);
            /** @var Licence $licence */
            $licence = $issued['licence'];
            $licence->forceFill(['status' => Licence::STATUS_ACTIVE, 'activated_at' => now()])->save();

            $fixture = $context->run((string) $tenant->getKey(), fn () => $this->school($tenant, $licence, $n, $perSchool, $n <= $feeSchools));
            $tenant->forceFill(['status' => Tenant::STATUS_ACTIVE])->save();

            // Written after every school, so a stopped run can be resumed (made schools are skipped).
            $previous = File::exists($path) ? (array) json_decode((string) File::get($path), true) : [];
            File::put($path, (string) json_encode(['password' => self::PASSWORD, 'schools' => [...($previous['schools'] ?? []), $fixture]], JSON_PRETTY_PRINT));
            $this->line(sprintf('%s ready (%d students) after %.0f s', $code, $perSchool, microtime(true) - $started));
        }

        $this->info("Fixtures written to {$path}.");

        return self::SUCCESS;
    }

    /** @return array<string, mixed> what k6 needs for this school */
    private function school(Tenant $tenant, Licence $licence, int $n, int $perSchool, bool $fees): array
    {
        $account = fn (string $name, string $email, array $roles, bool $mfa = false): User => User::create([
            'name' => $name, 'email' => $email, 'phone' => '+9198'.str_pad((string) random_int(0, 99_999_999), 8, '0', STR_PAD_LEFT),
            'password' => self::PASSWORD, 'roles' => $roles, 'status' => User::STATUS_ACTIVE,
            'mfa' => ['required' => $mfa, 'enabled' => false],
        ]);
        $principal = $account("Principal {$n}", "principal@load{$n}.test", [Role::Principal->value], true);
        $configs = app(SchoolConfigService::class);
        $config = $configs->initialise($tenant, $licence->licensedModules(), $principal);
        // Teachers may change today's register all week, so the test can run at any hour.
        $body = $config->body;
        $body['policies']['attendance']['edit_window_hours'] = 168;
        $configs->save($principal, $body, (int) $config->version, 'Load test: long edit window');

        $year = AcademicYear::create(['name' => '2026-27', 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31']);
        app(Structure::class)->makeCurrent($year);
        $yearId = (string) $year->getKey();
        $maths = Subject::create(['code' => 'MAT', 'name' => 'Mathematics']);

        $sections = [];
        foreach (range(1, 10) as $grade) {
            app(Structure::class)->createClass($year, ['grade' => (string) $grade, 'name' => "Class {$grade}", 'wing' => null, 'sections' => ['A', 'B', 'C', 'D']]);
        }
        $teachers = [];
        foreach (Section::query()->where('year_id', $yearId)->orderBy('label')->get() as $i => $section) {
            $user = $account("Teacher {$n}-{$section->label}", sprintf('t%02d@load%d.test', $i + 1, $n), [Role::ClassTeacher->value, Role::SubjectTeacher->value]);
            $staff = Staff::create(['employee_code' => sprintf('EMP-%d-%02d', $n, $i + 1), 'name' => $user->name, 'type' => 'teaching', 'email' => $user->email, 'user_id' => (string) $user->getKey(), 'status' => Staff::STATUS_ACTIVE]);
            $section->fill(['class_teacher_id' => (string) $staff->getKey()])->save();
            app(Structure::class)->assign((string) $section->getKey(), (string) $maths->getKey(), (string) $staff->getKey());
            $sections[] = $section;
            $teachers[(string) $section->getKey()] = $user;
        }
        $collector = $account("Collector {$n}", "collector@load{$n}.test", [Role::Staff->value, 'fee_collector']);

        // Students, enrolments and guardians in bulk (spread over the 40 sections).
        $now = new UTCDateTime;
        $students = $enrolments = $guardians = [];
        for ($s = 0; $s < $perSchool; $s++) {
            $section = $sections[$s % count($sections)];
            $studentId = new ObjectId;
            $guardianId = new ObjectId;
            $name = 'Student '.$n.'-'.($s + 1);
            $students[] = ['_id' => $studentId, 'tenant_id' => (string) $tenant->getKey(), 'admission_no' => sprintf('L%03d-%05d', $n, $s + 1), 'name' => $name, 'dob' => '2014-06-01',
                'gender' => $s % 2 ? 'male' : 'female', 'status' => 'active', 'guardian_ids' => [(string) $guardianId], 'ids' => [], 'custom' => [], 'created_at' => $now, 'updated_at' => $now];
            $enrolments[] = ['_id' => new ObjectId, 'tenant_id' => (string) $tenant->getKey(), 'student_id' => (string) $studentId, 'year_id' => $yearId, 'section_id' => (string) $section->getKey(),
                'roll_no' => intdiv($s, count($sections)) + 1, 'custom' => [], 'created_at' => $now, 'updated_at' => $now];
            $guardians[] = ['_id' => $guardianId, 'tenant_id' => (string) $tenant->getKey(), 'name' => 'Guardian of '.$name, 'relation' => 'mother', 'phone' => '+9195'.str_pad((string) ($n * 100000 + $s), 8, '0', STR_PAD_LEFT),
                'student_ids' => [(string) $studentId], 'user_id' => null, 'email' => null, 'created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk($students, 500) as $chunk) {
            Student::raw(fn ($c) => $c->insertMany($chunk));
        }
        foreach (array_chunk($enrolments, 500) as $chunk) {
            Enrolment::raw(fn ($c) => $c->insertMany($chunk));
        }
        foreach (array_chunk($guardians, 500) as $chunk) {
            Guardian::raw(fn ($c) => $c->insertMany($chunk));
        }

        // One open Maths class test per section, so the markbook can be saved.
        $test = app(Assessments::class)->create($principal, [
            'kind' => 'exam', 'type' => 'annual', 'name' => 'Load test', 'term' => 'Year', 'component' => null, 'from' => '2026-11-01', 'to' => '2026-11-05',
            'papers' => array_map(fn (Section $s) => ['section_id' => (string) $s->getKey(), 'subject_id' => (string) $maths->getKey(), 'max_marks' => 100], $sections),
        ]);
        $papers = [];
        foreach (Paper::query()->where('assessment_id', (string) $test->getKey())->get() as $paper) {
            app(PaperLifecycle::class)->open($principal, $paper);
            $papers[$paper->section_id] = (string) $paper->getKey();
        }

        $feeStudents = [];
        if ($fees) {
            $head = FeeHead::create(['name' => 'Tuition', 'code' => 'TUITION']);
            FeePlan::create(['year_id' => $yearId, 'name' => 'Regular', 'grades' => array_map('strval', range(1, 10)), 'instalments' => [
                ['key' => 'q1', 'label' => 'Quarter 1', 'due_on' => '2026-04-10', 'lines' => [['head_id' => (string) $head->getKey(), 'amount_paise' => 1_000_000]]],
                ['key' => 'q2', 'label' => 'Quarter 2', 'due_on' => '2026-07-10', 'lines' => [['head_id' => (string) $head->getKey(), 'amount_paise' => 1_000_000]]],
            ], 'fine' => ['mode' => 'none', 'amount_paise' => 0, 'grace_days' => 0, 'cap_paise' => null], 'discounts' => []]);
            $rows = array_map(fn (array $e) => ['_id' => new ObjectId, 'tenant_id' => (string) $tenant->getKey(), 'student_id' => $e['student_id'], 'year_id' => $yearId, 'joined_on' => '2026-04-01', 'created_at' => $now, 'updated_at' => $now], $enrolments);
            foreach (array_chunk($rows, 500) as $chunk) {
                StudentFee::raw(fn ($c) => $c->insertMany($chunk));
            }
            app(DemandRaiser::class)->raise($year);
            $feeStudents = array_map(fn (array $e) => $e['student_id'], array_slice($enrolments, 0, 200));
        }

        return [
            'code' => $tenant->code,
            'teachers' => array_map(fn (Section $s) => ['email' => $teachers[(string) $s->getKey()]->email, 'section_id' => (string) $s->getKey(), 'paper_id' => $papers[(string) $s->getKey()] ?? null], $sections),
            'collector' => $fees ? ['email' => $collector->email, 'students' => $feeStudents] : null,
        ];
    }
}
