<?php

namespace Database\Seeders;

use App\Modules\AcademicStructure\Domain\Structure;
use App\Modules\AcademicStructure\Models\AcademicYear;
use App\Modules\AcademicStructure\Models\Section;
use App\Modules\AcademicStructure\Models\Subject;
use App\Modules\Audit\Domain\AuditRecorder;
use App\Modules\Customisation\Models\CustomField;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Licensing\Domain\LicenceIssuer;
use App\Modules\Licensing\Models\Licence;
use App\Modules\People\Domain\ParentLogins;
use App\Modules\People\Domain\StudentLogins;
use App\Modules\People\Models\Enrolment;
use App\Modules\People\Models\Guardian;
use App\Modules\People\Models\Staff;
use App\Modules\People\Models\Student;
use App\Modules\Tenancy\Domain\SchoolConfigService;
use App\Modules\Tenancy\Domain\TenantProvisioner;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * The demo school from docs/design: Sunrise Public School, Bhopal (CBSE),
 * year 2026-27, with the made-up staff, classes and students of the screen
 * designs. Later phases add their own demo data here.
 *
 *   php artisan db:seed --class=DemoSchoolSeeder
 *
 * The one exception to "nobody types another person's password": every demo
 * account gets the same known development password, DEMO_PASSWORD below.
 * That is why this seeder runs only when APP_ENV is local or testing, and
 * refuses to run anywhere else. The demo sign-ins are listed in
 * docs/report/how-to/demo-school.md. Roles from Clerk up set up an
 * authenticator at their first sign-in, as everyone does.
 */
class DemoSchoolSeeder extends Seeder
{
    public const CODE = 'SUNRISE-BPL';

    /** Development only. Never used outside APP_ENV local or testing. */
    public const DEMO_PASSWORD = 'sunrise-demo-2026';

    private const CLASS_7B = [
        'Aanya Sharma', 'Aarav Patel', 'Arjun Singh', 'Diya Gupta', 'Farhan Khan', 'Ishita Rao', 'Kabir Mehta', 'Meera Joshi', 'Nikhil Verma',
        'Pari Agarwal', 'Pranav Iyer', 'Riya Kapoor', 'Rohit Yadav', 'Saanvi Desai', 'Samar Chauhan', 'Tanvi Kulkarni', 'Vihaan Reddy',
        'Zoya Ansari', 'Aditi Mishra', 'Ayaan Qureshi', 'Bhavna Thakur', 'Dev Malhotra', 'Esha Banerjee', 'Gautam Nair', 'Hina Siddiqui',
        'Ira Saxena', 'Jai Bhatia', 'Kriti Pandey', 'Laksh Arora', 'Mahi Sinha', 'Neel Chatterjee', 'Ojas Tiwari', 'Prisha Menon',
        'Reyansh Dubey', 'Sara Fernandes', 'Shaurya Rathore', 'Tara Joshi', 'Uday Bhatt', 'Vanya Pillai', 'Yash Goyal', 'Zara Mirza',
    ];

    private const CLASS_8A = [
        'Aditya Kulkarni', 'Ananya Bose', 'Bhavya Jain', 'Chirag Mehta', 'Devika Pillai', 'Eshan Reddy', 'Fatima Siddiqui',
        'Gaurav Thakur', 'Harini Subramaniam', 'Ishaan Chopra', 'Jaya Mishra', 'Karan Malhotra',
    ];

    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('DemoSchoolSeeder runs only when APP_ENV is local or testing: it gives every demo account one known password.');
        }

        if (Tenant::findByCode(self::CODE) !== null) {
            $this->command?->warn('The demo school '.self::CODE.' already exists. Nothing was changed.');

            return;
        }

        $tenant = app(TenantProvisioner::class)->create([
            'code' => self::CODE, 'name' => 'Sunrise Public School', 'email' => 'office@sunrise.test', 'phone' => '+919800000000',
        ]);

        $licence = app(LicenceIssuer::class)->issue($tenant, [
            'plan' => 'standard', 'modules' => config('licensing.modules'), 'students' => 800, 'staff' => 60,
            'valid_from' => '2026-04-01', 'valid_to' => '2027-03-31',
        ])['licence'];

        app(TenantContext::class)->run($tenant->id, fn () => AuditRecorder::muted(fn () => $this->build($tenant, $licence)));

        $this->command?->info('Demo school '.self::CODE.' is ready. Sign-ins: docs/report/how-to/demo-school.md (password '.self::DEMO_PASSWORD.').');
    }

    private function build(Tenant $tenant, Licence $licence): void
    {
        $chief = $this->account('Vikram Malviya', 'chief@sunrise.test', '+919800000010', [Role::Chief]);
        $licence->forceFill(['status' => Licence::STATUS_ACTIVE, 'activated_at' => now()])->save();

        $configs = app(SchoolConfigService::class);
        $configs->initialise($tenant, $licence->licensedModules(), $chief);
        $body = $configs->current()->body;
        $body['school'] = array_merge($body['school'], ['name' => 'Sunrise Public School', 'short_name' => 'SPS', 'board' => 'CBSE']);
        $body['branding']['accent'] = '#0F766E';
        $body['sign_in']['methods'] = ['password', 'parent_otp', 'student_login'];
        $configs->save($chief, $body, $configs->current()->version, 'Demo school set-up');
        Tenant::query()->where('_id', $tenant->id)->update(['status' => Tenant::STATUS_ACTIVE]);

        // Staff, each with a staff record linked to the account.
        $people = [
            'principal' => ['Dr. Meera Iyer', 'principal@sunrise.test', [Role::Principal], 'Principal'],
            'vp' => ['Kavita Joshi', 'vp.primary@sunrise.test', [Role::VicePrincipal], 'Vice Principal (Primary)'],
            'clerk' => ['Ramesh Kumar', 'clerk@sunrise.test', [Role::Clerk], 'Office Clerk'],
            'anjali' => ['Anjali Verma', 'anjali.verma@sunrise.test', [Role::ClassTeacher, Role::SubjectTeacher], 'TGT Mathematics'],
            'pooja' => ['Pooja Saxena', 'pooja.saxena@sunrise.test', [Role::ClassTeacher, Role::SubjectTeacher], 'TGT English'],
            'rakesh' => ['Rakesh Sharma', 'rakesh.sharma@sunrise.test', [Role::SubjectTeacher], 'TGT Science'],
            'sunita' => ['Sunita Rao', 'sunita.rao@sunrise.test', [Role::SubjectTeacher], 'TGT Hindi'],
            'priya' => ['Priya Nair', 'priya.nair@sunrise.test', [Role::ClassTeacher, Role::SubjectTeacher], 'PRT'],
            'deepak' => ['Deepak Yadav', 'deepak.yadav@sunrise.test', [Role::SubjectTeacher], 'PET'],
            'kamla' => ['Kamla Devi', 'kamla.devi@sunrise.test', [Role::Staff], 'Office Assistant'],
        ];

        $staff = ['chief' => $this->staffRecord($chief, 'Trustee', 1)];
        $n = 2;
        foreach ($people as $key => [$name, $email, $roles, $designation]) {
            $user = $this->account($name, $email, '+9198000000'.str_pad((string) (10 + $n), 2, '0', STR_PAD_LEFT), $roles);
            $staff[$key] = $this->staffRecord($user, $designation, $n++, $key === 'vp' ? ['primary'] : [], $key === 'kamla' ? 'non_teaching' : 'teaching');
        }

        // Year, classes, sections, subjects, teachers.
        $structure = app(Structure::class);
        $year = AcademicYear::create(['name' => '2026-27', 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31']);
        $structure->makeCurrent($year);

        $sections = [];
        foreach ([['3', 'primary', ['A']], ['6', 'middle', ['A', 'B']], ['7', 'middle', ['A', 'B']], ['8', 'middle', ['A', 'B']], ['9', 'secondary', ['A', 'B']]] as $i => [$grade, $wing, $names]) {
            $class = $structure->createClass($year, ['grade' => $grade, 'name' => 'Class '.$grade, 'order' => $i, 'wing' => $wing, 'sections' => $names]);
            foreach (Section::query()->where('class_id', (string) $class->getKey())->get() as $section) {
                $sections[$section->label] = $section;
            }
        }

        $subjects = [];
        foreach (['MATH' => 'Mathematics', 'SCI' => 'Science', 'ENG' => 'English', 'HIN' => 'Hindi', 'SST' => 'Social Science', 'PE' => 'Physical Education'] as $code => $name) {
            $subjects[$code] = Subject::create(['code' => $code, 'name' => $name, 'kind' => $code === 'PE' ? 'co_scholastic' : 'scholastic']);
        }

        $sections['7-B']->forceFill(['class_teacher_id' => (string) $staff['anjali']->getKey()])->save();
        $sections['8-A']->forceFill(['class_teacher_id' => (string) $staff['pooja']->getKey()])->save();
        $sections['3-A']->forceFill(['class_teacher_id' => (string) $staff['priya']->getKey()])->save();

        foreach ([['7-B', 'MATH', 'anjali'], ['8-A', 'MATH', 'anjali'], ['7-B', 'SCI', 'rakesh'], ['8-A', 'SCI', 'rakesh'], ['8-B', 'SCI', 'rakesh'],
            ['7-B', 'ENG', 'pooja'], ['8-A', 'ENG', 'pooja'], ['7-B', 'HIN', 'sunita'], ['8-A', 'HIN', 'sunita'], ['7-B', 'PE', 'deepak'], ['8-A', 'PE', 'deepak'], ['3-A', 'ENG', 'priya']] as [$label, $code, $who]) {
            $structure->assign((string) $sections[$label]->getKey(), (string) $subjects[$code]->getKey(), (string) $staff[$who]->getKey());
        }

        // Students. Aarav (7-B) and Riya (3-A) Patel are siblings with one parent login.
        $admission = 1;
        $make = function (string $name, Section $section, int $roll, ?string $phone = null, ?string $parent = null) use ($year, &$admission): Student {
            $no = 'SPS/2026/'.str_pad((string) $admission++, 4, '0', STR_PAD_LEFT);
            $phone ??= '+9197000'.str_pad((string) $admission, 5, '0', STR_PAD_LEFT);
            $student = Student::create([
                'admission_no' => $no, 'name' => $name, 'dob' => sprintf('20%02d-%02d-%02d', 26 - (int) $section->label[0] - 6, ($admission % 12) + 1, ($admission % 27) + 1),
                'gender' => $admission % 2 === 0 ? 'female' : 'male', 'status' => 'active',
            ]);
            Enrolment::create(['student_id' => (string) $student->getKey(), 'year_id' => (string) $year->getKey(), 'section_id' => (string) $section->getKey(), 'roll_no' => $roll]);
            $guardian = Guardian::query()->where('phone', $phone)->first() ?? Guardian::create(['name' => $parent ?? 'Parent of '.$name, 'relation' => 'father', 'phone' => $phone]);
            $guardian->forceFill(['student_ids' => array_values(array_unique([...$guardian->student_ids, (string) $student->getKey()]))])->save();
            $student->forceFill(['guardian_ids' => [(string) $guardian->getKey()]])->save();

            return $student;
        };

        $aarav = null;
        foreach (self::CLASS_7B as $i => $name) {
            $student = $make($name, $sections['7-B'], $i + 1, $name === 'Aarav Patel' ? '+919800000001' : null, $name === 'Aarav Patel' ? 'Suresh Patel' : null);
            $aarav = $name === 'Aarav Patel' ? $student : $aarav;
        }
        foreach (self::CLASS_8A as $i => $name) {
            $make($name, $sections['8-A'], $i + 1);
        }
        $make('Riya Patel', $sections['3-A'], 1, '+919800000001', 'Suresh Patel');
        foreach (['Bhavya Rao', 'Ojas Mehta', 'Kiara Das'] as $i => $name) {
            $make($name, $sections['3-A'], $i + 2);
        }

        // Parent login for Suresh Patel (both children), with the known dev password too.
        $suresh = Guardian::query()->where('phone', '+919800000001')->firstOrFail();
        $parent = app(ParentLogins::class)->enable($chief, $suresh);
        $parent->forceFill(['email' => 'parent@sunrise.test', 'password' => self::DEMO_PASSWORD])->save();

        // A student account for Aarav (student sign-in is on in the demo).
        if ($aarav instanceof Student) {
            $login = StudentLogins::loginName($aarav->admission_no);
            $studentUser = User::create(['name' => $aarav->name, 'username' => $login, 'password' => self::DEMO_PASSWORD, 'roles' => [Role::Student->value], 'status' => User::STATUS_ACTIVE]);
            $aarav->forceFill(['user_id' => (string) $studentUser->getKey(), 'login_name' => $login])->save();
        }

        // Custom fields: a school-wide House and Anjali's class-only Seat row on 7-B.
        CustomField::create(['entity' => 'student', 'key' => 'house', 'label' => ['en' => 'House', 'hi' => 'सदन'], 'type' => 'dropdown',
            'options' => ['Blue', 'Green', 'Red', 'Yellow'], 'scope' => ['level' => 'school', 'ref' => null], 'owner_level' => 80, 'status' => 'active',
            'owner' => ['id' => (string) $staff['principal']->user_id, 'name' => 'Dr. Meera Iyer']]);
        CustomField::create(['entity' => 'enrolment', 'key' => 'seat_row', 'label' => ['en' => 'Seat row', 'hi' => 'बैठने की पंक्ति'], 'type' => 'dropdown',
            'options' => ['Row 1', 'Row 2', 'Row 3', 'Row 4'], 'scope' => ['level' => 'section', 'ref' => (string) $sections['7-B']->getKey()], 'owner_level' => 50,
            'status' => 'active', 'owner' => ['id' => (string) $staff['anjali']->user_id, 'name' => 'Anjali Verma']]);
    }

    /**
     * @param  list<Role>  $roles
     */
    private function account(string $name, string $email, string $phone, array $roles): User
    {
        $required = max(array_map(fn (Role $r) => $r->level(), $roles)) >= Role::MFA_REQUIRED_FROM;

        return User::create([
            'name' => $name, 'email' => $email, 'phone' => $phone, 'password' => self::DEMO_PASSWORD,
            'roles' => array_map(fn (Role $r) => $r->value, $roles), 'status' => User::STATUS_ACTIVE,
            'mfa' => ['required' => $required, 'enabled' => false],
        ]);
    }

    /**
     * @param  list<string>  $wings
     */
    private function staffRecord(User $user, string $designation, int $n, array $wings = [], string $type = 'teaching'): Staff
    {
        return Staff::create([
            'employee_code' => 'SPS-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT), 'name' => $user->name, 'designation' => $designation,
            'type' => $type, 'email' => $user->email, 'phone' => $user->phone, 'joined_on' => '2026-04-01',
            'user_id' => (string) $user->getKey(), 'wings' => $wings, 'status' => Staff::STATUS_ACTIVE,
        ]);
    }
}
