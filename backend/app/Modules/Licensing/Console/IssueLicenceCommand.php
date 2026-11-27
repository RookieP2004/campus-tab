<?php

namespace App\Modules\Licensing\Console;

use App\Modules\Licensing\Domain\LicenceIssuer;
use App\Modules\Tenancy\Models\Tenant;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Platform console: issue a licence key for a school. The key is printed
 * once and never stored; give it to the school's contact.
 *
 *   php artisan platform:issue-licence SUNRISE-BPL --plan=standard --students=800 --staff=60 --valid-to=2027-03-31
 */
class IssueLicenceCommand extends Command
{
    protected $signature = 'platform:issue-licence
        {code : The school code}
        {--plan=standard : starter, standard or premium}
        {--modules= : Comma-separated modules (default: all)}
        {--students=500 : Student seat limit}
        {--staff=50 : Staff seat limit}
        {--valid-from= : First valid day, YYYY-MM-DD (default: today)}
        {--valid-to= : Last valid day, YYYY-MM-DD (default: one year from today)}';

    protected $description = 'Issue a signed licence key for a school';

    public function handle(LicenceIssuer $issuer): int
    {
        $tenant = Tenant::findByCode((string) $this->argument('code'));

        if ($tenant === null) {
            $this->error('No school has this code. Create it first with platform:create-school.');

            return self::FAILURE;
        }

        $modules = $this->option('modules');
        $modules = is_string($modules) && $modules !== ''
            ? array_values(array_filter(array_map('trim', explode(',', $modules))))
            : config('licensing.modules');

        try {
            $result = $issuer->issue($tenant, [
                'plan' => (string) $this->option('plan'),
                'modules' => $modules,
                'students' => (int) $this->option('students'),
                'staff' => (int) $this->option('staff'),
                'valid_from' => (string) ($this->option('valid-from') ?: now('Asia/Kolkata')->toDateString()),
                'valid_to' => (string) ($this->option('valid-to') ?: now('Asia/Kolkata')->addYear()->subDay()->toDateString()),
            ]);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        } catch (DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $licence = $result['licence'];

        $this->info("Licence issued for {$tenant->name} ({$tenant->code}).");
        $this->table(['Plan', 'Modules', 'Seats (students / staff)', 'Valid'], [[
            $licence->plan,
            implode(', ', $licence->modules),
            "{$licence->seats['students']} / {$licence->seats['staff']}",
            "{$licence->valid_from} to {$licence->valid_to}",
        ]]);
        $this->newLine();
        $this->line('  Licence key (shown once, not stored):  <options=bold>'.$result['key'].'</>');
        $this->newLine();

        return self::SUCCESS;
    }
}
