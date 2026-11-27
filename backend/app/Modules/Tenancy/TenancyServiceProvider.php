<?php

namespace App\Modules\Tenancy;

use App\Modules\Tenancy\Models\SchoolConfig;
use App\Modules\Tenancy\Policies\SchoolConfigPolicy;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantQueryGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use MongoDB\Laravel\Connection;

class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped: a fresh, empty context for every request and queued job.
        $this->app->scoped(TenantContext::class);
    }

    public function boot(): void
    {
        Gate::policy(SchoolConfig::class, SchoolConfigPolicy::class);

        $this->registerQueryGuard();
    }

    private function registerQueryGuard(): void
    {
        $mode = (string) config('tenancy.query_guard', 'log');

        if ($mode === 'off') {
            return;
        }

        $connection = DB::connection('mongodb');

        if (! $connection instanceof Connection) {
            return;
        }

        $connection->getClient()?->addSubscriber(TenantQueryGuard::instance());
    }
}
