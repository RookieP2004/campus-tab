<?php

namespace App\Modules\Platform\Console;

use App\Modules\Platform\Domain\SchoolConsole;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Http\ApiException;
use Illuminate\Console\Command;

/**
 * Platform console: revoke a school's licence. The school is shut out at
 * once (its data stays). As in the web console, the school code must be
 * typed again to confirm.
 *
 *   php artisan platform:revoke-licence SUNRISE-BPL --reason="Contract ended"
 */
class RevokeLicenceCommand extends Command
{
    protected $signature = 'platform:revoke-licence
        {code : The school code}
        {--reason= : Why (written to both audit logs)}
        {--confirm= : The school code again (asked for when not given)}';

    protected $description = 'Revoke a school\'s licence (the school loses access at once)';

    public function handle(SchoolConsole $schools): int
    {
        $tenant = Tenant::findByCode((string) $this->argument('code'));

        if ($tenant === null) {
            $this->error('No school has this code.');

            return self::FAILURE;
        }

        $reason = trim((string) $this->option('reason'));

        if (mb_strlen($reason) < 5) {
            $this->error('Give a reason with --reason="…" (at least 5 characters).');

            return self::FAILURE;
        }

        $confirm = $this->option('confirm');
        $confirm = is_string($confirm) && $confirm !== ''
            ? $confirm
            : (string) $this->ask("This shuts {$tenant->name} out at once. Type the school code ({$tenant->code}) to confirm");

        try {
            $licence = $schools->revokeLicence(null, $tenant, $confirm, $reason);
        } catch (ApiException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Revoked licence …{$licence->key_last4} of {$tenant->name} ({$tenant->code}).");

        return self::SUCCESS;
    }
}
