<?php

namespace Database\Seeders;

use App\Modules\AcademicStructure\Models\AcademicYear;
use App\Modules\Fees\Domain\Adjustments;
use App\Modules\Fees\Domain\DemandRaiser;
use App\Modules\Fees\Domain\Ledger;
use App\Modules\Fees\Domain\Payments;
use App\Modules\Fees\Models\FeeCategory;
use App\Modules\Fees\Models\FeeHead;
use App\Modules\Fees\Models\FeePlan;
use App\Modules\Fees\Models\GatewayAccount;
use App\Modules\Fees\Models\StudentFee;
use App\Modules\Identity\Models\User;
use App\Modules\People\Models\Enrolment;
use App\Modules\People\Models\Student;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Phase 8 demo data for Sunrise Public School (made-up amounts only):
 *
 *  - heads (Tuition, Annual charges, Transport, Exam), categories
 *    (Regular, Staff ward), one plan for every class: four quarters
 *    (April, July, October, January), a ₹50 late fine after 5 days, a 10%
 *    sibling discount on tuition;
 *  - every enrolled student charged from the start of the year, dues
 *    raised up to now (Aarav Patel and Riya Patel are siblings);
 *  - Kamla Devi has the Fee Collector duty; two counter payments today (one
 *    by her, one by Ramesh Kumar) so both have a day to close;
 *  - a waiver Ramesh Kumar proposed, waiting for Dr. Meera Iyer;
 *  - the local test gateway switched on, so "Pay now" works with no internet
 *    (the fake driver, local only; the webhook is sent by the queue worker).
 *
 * DemoSchoolSeeder calls it. It does nothing if the school already has fee heads.
 */
class DemoFeesSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('DemoFeesSeeder runs only when APP_ENV is local or testing.');
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
        if (FeeHead::query()->exists()) {
            $this->command?->warn('The demo school already has fee heads. Nothing was changed.');

            return;
        }
        $year = AcademicYear::current() ?? throw new RuntimeException('The demo school has no current year.');
        $user = fn (string $email) => User::query()->where('email', $email)->firstOrFail();
        [$principal, $clerk, $kamla] = [$user('principal@sunrise.test'), $user('clerk@sunrise.test'), $user('kamla.devi@sunrise.test')];
        $kamla->forceFill(['roles' => array_values(array_unique([...$kamla->roles, 'fee_collector']))])->save();

        $heads = [];
        foreach (['TUITION' => 'Tuition', 'ANNUAL' => 'Annual charges', 'TRANSPORT' => 'Transport', 'EXAM' => 'Exam'] as $code => $name) {
            $heads[$code] = (string) FeeHead::create(['name' => $name, 'code' => $code])->getKey();
        }
        foreach (['REGULAR' => 'Regular', 'STAFF_WARD' => 'Staff ward'] as $code => $name) {
            FeeCategory::create(['name' => $name, 'code' => $code]);
        }
        $quarter = fn (string $label, string $due, array $extra = []) => ['key' => strtolower(str_replace(' ', '-', $label)), 'label' => $label, 'due_on' => $due, 'lines' => [
            ['head_id' => $heads['TUITION'], 'amount_paise' => 960000], ['head_id' => $heads['TRANSPORT'], 'amount_paise' => 360000], ...$extra,
        ]];
        $start = substr($year->starts_on, 0, 4);
        $next = (string) ((int) $start + 1);
        FeePlan::create([
            'year_id' => (string) $year->getKey(), 'name' => 'Regular 2026-27', 'grades' => ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10'],
            'instalments' => [
                $quarter('Quarter 1', "{$start}-04-10", [['head_id' => $heads['ANNUAL'], 'amount_paise' => 600000]]),
                $quarter('Quarter 2', "{$start}-07-10"),
                $quarter('Quarter 3', "{$start}-10-10", [['head_id' => $heads['EXAM'], 'amount_paise' => 50000]]),
                $quarter('Quarter 4', "{$next}-01-10"),
            ],
            'fine' => ['mode' => 'flat', 'amount_paise' => 5000, 'grace_days' => 5, 'cap_paise' => null],
            'discounts' => [['key' => 'sibling', 'label' => 'Sibling discount', 'condition' => 'sibling', 'kind' => 'percent', 'value' => 1000, 'head_ids' => [$heads['TUITION']]]],
        ]);

        // The demo students were all here from the start of the year.
        foreach (Enrolment::query()->where('year_id', (string) $year->getKey())->get() as $enrolment) {
            StudentFee::create(['student_id' => $enrolment->student_id, 'year_id' => (string) $year->getKey(), 'joined_on' => $year->starts_on]);
        }
        app(DemandRaiser::class)->raise($year);

        $payments = app(Payments::class);
        $aarav = Student::query()->where('name', 'Aarav Patel')->first();
        $other = Student::query()->where('name', '!=', 'Aarav Patel')->where('status', 'active')->orderBy('admission_no')->first();
        if ($aarav instanceof Student) {
            // Quarters 1 and 2 paid at the counter earlier; Quarter 3 still owed (with the late fine).
            foreach (array_slice(app(Ledger::class)->demandStates($payments->openDemands((string) $aarav->getKey(), (string) $year->getKey(), null)), 0, 2, true) as $id => $state) {
                $payments->take($aarav, ['mode' => 'upi_counter', 'amount_paise' => $state['balance'], 'reference' => 'UPI-'.substr($id, -6), 'demand_ids' => [$id]], ['id' => (string) $clerk->getKey(), 'name' => $clerk->name], null, $clerk);
            }
        }
        if ($other instanceof Student) {
            $payments->take($other, ['mode' => 'cash', 'amount_paise' => 500000], ['id' => (string) $kamla->getKey(), 'name' => $kamla->name], null, $kamla);
            app(Adjustments::class)->propose($clerk, $other, ['kind' => 'waiver', 'amount_paise' => 100000, 'reason' => 'Family hardship this term; letter from the guardian on file.']);
        }

        GatewayAccount::create([
            'provider' => 'fake', 'key_id' => 'fake_demo_key', 'key_secret' => 'fake-demo-secret-0001', 'webhook_secret' => 'fake-demo-webhook-0001',
            'updated_by' => ['id' => (string) $principal->getKey(), 'name' => 'Demo set-up'],
        ]);
    }
}
