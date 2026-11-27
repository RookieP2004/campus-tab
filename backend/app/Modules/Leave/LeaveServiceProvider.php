<?php

namespace App\Modules\Leave;

use App\Modules\Leave\Models\LeaveDelegation;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Policies\LeavePolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Leave: staff and student leave, leave types, the balance ledger,
 * delegation, reminders and the needs-cover list.
 */
class LeaveServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        foreach ([LeaveRequest::class, LeaveType::class, LeaveDelegation::class] as $model) {
            Gate::policy($model, LeavePolicy::class);
        }
    }
}
