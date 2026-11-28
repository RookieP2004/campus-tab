<?php

namespace Database\Seeders;

use App\Modules\Identity\Models\User;
use App\Modules\People\Models\Student;
use App\Modules\Privacy\Domain\Consents;
use App\Modules\Privacy\Domain\DataRequests;
use App\Modules\Privacy\Domain\Notices;
use App\Modules\Privacy\Models\PrivacyNotice;
use App\Modules\Tenancy\Domain\SchoolConfigService;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Phase 9 demo data for Sunrise Public School (made-up people only):
 *
 *  - a SAMPLE privacy notice, version 1, marked as needing a legal review;
 *  - the grievance contact on the school profile (a made-up name and a
 *    .test address);
 *  - Suresh Patel (parent) has said yes to keeping Aarav's PEN and no to
 *    public photos, from his own account;
 *  - an access request from Suresh Patel about Riya, waiting in the inbox.
 *
 * DemoSchoolSeeder calls it. It does nothing if the school already has a
 * privacy notice.
 */
class DemoPrivacySeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('DemoPrivacySeeder runs only when APP_ENV is local or testing.');
        }
        $context = app(TenantContext::class);
        if ($context->has()) {
            $this->build();

            return;
        }
        $tenant = Tenant::findByCode(DemoSchoolSeeder::CODE) ?? throw new RuntimeException('Load the demo school first (DemoSchoolSeeder).');
        $context->run($tenant->id, fn () => $this->build());
    }

    private function build(): void
    {
        if (PrivacyNotice::query()->exists()) {
            $this->command?->warn('The demo school already has a privacy notice. Nothing was changed.');

            return;
        }
        $chief = User::query()->where('email', 'chief@sunrise.test')->firstOrFail();
        $parent = User::query()->where('email', 'parent@sunrise.test')->firstOrFail();

        $configs = app(SchoolConfigService::class);
        $current = $configs->current();
        $body = $current->body;
        $body['school']['grievance'] = ['name' => 'Anita Desai', 'role' => 'Grievance officer', 'email' => 'privacy@sunrise.test', 'phone' => '+910000000000', 'address' => null];
        $configs->save($chief, $body, (int) $current->version, 'Grievance contact (demo)');

        $notices = app(Notices::class);
        $notices->saveDraft([
            'title' => 'How Sunrise Public School uses personal data (sample)',
            'body' => implode("\n\n", [
                'SAMPLE TEXT FOR THE DEMO SCHOOL. A real school must have its notice checked by a lawyer before publishing it.',
                'Sunrise Public School keeps the personal data it needs to teach your child and keep them safe: names, dates of birth, classes, attendance, marks, fees and contact details of parents.',
                'Some uses are optional and need your consent: keeping the PEN or APAAR ID, using photos in public, and sharing details with outside partners. You can say yes or no for each, and change your answer at any time on your Privacy page.',
                'You can ask for a copy of your data, a correction, or for details to be erased. Write to the grievance officer named on this page.',
            ]),
            'summary_of_changes' => 'First version',
        ]);
        $version = (int) $notices->publish($chief)->version;

        $aarav = Student::query()->where('name', 'Aarav Patel')->firstOrFail();
        $riya = Student::query()->where('name', 'Riya Patel')->firstOrFail();
        $consents = app(Consents::class);
        $consents->answer($parent, (string) $aarav->getKey(), 'pen', true, $version);
        $consents->answer($parent, (string) $aarav->getKey(), 'photo_public', false, $version);

        app(DataRequests::class)->create($parent, ['type' => 'access', 'student_id' => (string) $riya->getKey(), 'details' => 'Please share a copy of the details the school keeps about Riya.']);
    }
}
