<?php

namespace App\Modules\Audit;

use App\Modules\Audit\Domain\AuditRecorder;
use App\Modules\Identity\Domain\AccessControl;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(AuditRecorder::class);
    }

    public function boot(): void
    {
        $access = fn (): AccessControl => $this->app->make(AccessControl::class);
        $deny = fn (): Response => Response::deny('You do not have permission to do this.', 'permission_denied');

        Gate::define('records.restore', fn (User $user) => $access()->allows($user, 'records.restore') ? Response::allow() : $deny());
        Gate::define('records.purge', fn (User $user) => $access()->allows($user, 'records.purge') ? Response::allow() : $deny());

        // Which entries a reader sees is narrowed in AuditLogController.
        Gate::define('audit.view-any', fn (User $user) => $access()->holds($user, 'audit.view') || $access()->holds($user, 'audit.view_academic')
            ? Response::allow()
            : $deny());
    }
}
