<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

/**
 * Phase 5: leave. Every index starts with tenant_id.
 */
return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $c): void {
            $c->index(['tenant_id', 'code'], 'tenant_code_unique', null, ['unique' => true]);
        });

        Schema::create('leave_ledger', function (Blueprint $c): void {
            $c->index(['tenant_id', 'staff_id', 'year_id', 'type_code']);
            $c->index(['tenant_id', 'request_id']);
        });

        Schema::create('leave_balance_locks', function (Blueprint $c): void {
            $c->index(['tenant_id', 'staff_id', 'year_id', 'type_code'], 'tenant_staff_year_type_unique', null, ['unique' => true]);
        });

        Schema::create('leave_requests', function (Blueprint $c): void {
            $c->index(['tenant_id', 'status', 'stage']);
            $c->index(['tenant_id', 'applicant_user_id', 'created_at']);
            $c->index(['tenant_id', 'applied_by_id', 'created_at']);
            $c->index(['tenant_id', 'applicant_student_id', 'status', 'from']);
            $c->index(['tenant_id', 'kind', 'status', 'from', 'to']);
        });

        Schema::create('leave_delegations', function (Blueprint $c): void {
            $c->index(['tenant_id', 'delegate_id', 'from', 'to']);
        });
    }

    public function down(): void
    {
        foreach (['leave_delegations', 'leave_requests', 'leave_balance_locks', 'leave_ledger', 'leave_types'] as $collection) {
            Schema::dropIfExists($collection);
        }
    }
};
