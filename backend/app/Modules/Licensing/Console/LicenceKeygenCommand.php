<?php

namespace App\Modules\Licensing\Console;

use App\Modules\Licensing\Domain\LicenceSigner;
use Illuminate\Console\Command;

/**
 * Prints a new Ed25519 signing key pair and key pepper for .env, or writes
 * them into backend/.env with --write (only where they are not set yet).
 *
 * Changing them later invalidates every licence issued with the old ones.
 */
class LicenceKeygenCommand extends Command
{
    protected $signature = 'licence:keygen {--write : Add the values to .env where they are missing}';

    protected $description = 'Generate the licence signing keys and key pepper';

    public function handle(): int
    {
        $pair = LicenceSigner::generateKeyPair();
        $values = [
            'LICENCE_KEY_PEPPER' => bin2hex(random_bytes(32)),
            'LICENCE_SIGNING_SECRET_KEY' => $pair['secret_key'],
            'LICENCE_SIGNING_PUBLIC_KEY' => $pair['public_key'],
        ];

        if (! $this->option('write')) {
            $this->line('Add these to backend/.env (never commit them):');
            $this->newLine();

            foreach ($values as $name => $value) {
                $this->line("{$name}={$value}");
            }

            return self::SUCCESS;
        }

        $path = base_path('.env');
        $env = is_file($path) ? (string) file_get_contents($path) : '';
        $added = [];

        foreach ($values as $name => $value) {
            if (preg_match('/^'.$name.'=.+$/m', $env) === 1) {
                continue;
            }

            $env = preg_match('/^'.$name.'=$/m', $env) === 1
                ? (string) preg_replace('/^'.$name.'=$/m', "{$name}={$value}", $env)
                : rtrim($env)."\n{$name}={$value}\n";
            $added[] = $name;
        }

        file_put_contents($path, $env);

        $this->info($added === [] ? 'All licence keys were already set; nothing changed.' : 'Added to .env: '.implode(', ', $added));

        return self::SUCCESS;
    }
}
