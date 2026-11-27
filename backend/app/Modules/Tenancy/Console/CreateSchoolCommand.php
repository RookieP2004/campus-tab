<?php

namespace App\Modules\Tenancy\Console;

use App\Modules\Platform\Domain\SchoolConsole;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Platform console: create a school record (status "pending").
 *
 *   php artisan platform:create-school SUNRISE-BPL "Sunrise Public School" --email=office@example.test --phone=+919800000000
 */
class CreateSchoolCommand extends Command
{
    protected $signature = 'platform:create-school
        {code : Short school code people type at sign-in, e.g. SUNRISE-BPL}
        {name : Full school name}
        {--email= : Registered contact email (activation OTP goes here)}
        {--phone= : Registered contact phone (activation OTP goes here)}';

    protected $description = 'Create a school (tenant) record, ready for a licence key';

    public function handle(SchoolConsole $schools): int
    {
        try {
            $tenant = $schools->createSchool(null, [
                'code' => (string) $this->argument('code'),
                'name' => (string) $this->argument('name'),
                'email' => $this->option('email'),
                'phone' => $this->option('phone'),
            ]);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        }

        $this->info("Created {$tenant->name} ({$tenant->code}), id {$tenant->id}, status {$tenant->status}.");
        $this->line("Next: php artisan platform:issue-licence {$tenant->code}");

        return self::SUCCESS;
    }
}
