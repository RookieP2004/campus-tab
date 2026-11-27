<?php

namespace App\Modules\Platform\Console;

use App\Modules\Identity\Models\PlatformAdmin;
use App\Modules\Platform\Domain\PlatformAdmins;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * The only way a platform admin account is made: on the server, by someone
 * who can run artisan there. There is no sign-up.
 *
 *   php artisan platform:create-admin "Made Up Admin" admin@campustab.test
 *   php artisan platform:create-admin --resend admin@campustab.test
 *
 * The account starts with no password. Its owner gets a one-time link (48
 * hours) by email to choose one, then sets up two-step sign-in at the first
 * console sign-in. The link is also printed once, for a server without mail.
 */
class CreateAdminCommand extends Command
{
    protected $signature = 'platform:create-admin
        {name? : Full name (not needed with --resend)}
        {email? : Work email; the set-password link goes here}
        {--resend= : Send a new set-password link to this existing admin email}';

    protected $description = 'Create a platform console admin (no sign-up exists)';

    public function handle(PlatformAdmins $admins): int
    {
        $resend = $this->option('resend');

        if (is_string($resend) && $resend !== '') {
            $admin = PlatformAdmin::query()->where('email', mb_strtolower(trim($resend)))->first();

            if (! $admin instanceof PlatformAdmin) {
                $this->error('No platform admin has this email.');

                return self::FAILURE;
            }

            $this->printLink($admins->sendLink($admin), 'A new link was sent; any earlier link no longer works.');

            return self::SUCCESS;
        }

        $name = (string) ($this->argument('name') ?? '');
        $email = (string) ($this->argument('email') ?? '');

        if ($name === '' || $email === '') {
            $this->error('Give a name and an email: php artisan platform:create-admin "Full Name" person@example.test');

            return self::FAILURE;
        }

        try {
            $result = $admins->create($name, $email);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        }

        $this->info("Created platform admin {$result['admin']->name} <{$result['admin']->email}>.");
        $this->printLink($result['link'], 'The link was also emailed.');

        return self::SUCCESS;
    }

    private function printLink(string $url, string $note): void
    {
        $hours = (int) config('identity.platform.set_link_hours', 48);

        $this->newLine();
        $this->line("  Set-password link (works once, for {$hours} hours; give it only to the admin):");
        $this->line('  <options=bold>'.$url.'</>');
        $this->line("  {$note} Two-step sign-in is set up at the first sign-in.");
        $this->newLine();
    }
}
