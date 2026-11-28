<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

/**
 * Phase 8: fees. Every index starts with tenant_id. The unique indexes
 * carry the money rules: one demand per student per plan instalment, one
 * receipt number per school per year, one counter per key, one gateway
 * order id, and one "paid" event per gateway payment (webhook idempotency).
 */
return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        Schema::create('fee_heads', fn (Blueprint $c) => $c->index(['tenant_id', 'code'], 'tenant_code_unique', null, ['unique' => true]));
        Schema::create('fee_categories', fn (Blueprint $c) => $c->index(['tenant_id', 'code'], 'tenant_code_unique', null, ['unique' => true]));
        Schema::create('fee_plans', fn (Blueprint $c) => $c->index(['tenant_id', 'year_id', 'status']));
        Schema::create('student_fees', fn (Blueprint $c) => $c->index(['tenant_id', 'student_id', 'year_id'], 'tenant_student_year_unique', null, ['unique' => true]));

        Schema::create('fee_demands', function (Blueprint $c): void {
            $c->index(['tenant_id', 'student_id', 'year_id', 'plan_id', 'instalment_key'], 'tenant_student_instalment_unique', null, ['unique' => true]);
            $c->index(['tenant_id', 'year_id', 'section_id']);
            $c->index(['tenant_id', 'year_id', 'due_on']);
        });

        Schema::create('fee_ledger', function (Blueprint $c): void {
            $c->index(['tenant_id', 'student_id', 'year_id', 'created_at']);
            $c->index(['tenant_id', 'demand_id']);
            $c->index(['tenant_id', 'payment_id']);
            $c->index(['tenant_id', 'type', 'created_at']);
        });

        Schema::create('payments', function (Blueprint $c): void {
            $c->index(['tenant_id', 'student_id', 'created_at']);
            $c->index(['tenant_id', 'collector_id', 'closing_id']);
            $c->index(['tenant_id', 'received_on', 'mode']);
            $c->index(['tenant_id', 'status']);
        });

        Schema::create('receipts', function (Blueprint $c): void {
            $c->index(['tenant_id', 'year_id', 'seq'], 'tenant_year_seq_unique', null, ['unique' => true]);
            $c->index(['tenant_id', 'number'], 'tenant_number_unique', null, ['unique' => true]);
            $c->index(['tenant_id', 'student_id', 'year_id']);
        });

        Schema::create('fee_counters', fn (Blueprint $c) => $c->index(['tenant_id', 'key'], 'tenant_key_unique', null, ['unique' => true]));
        Schema::create('fee_adjustments', fn (Blueprint $c) => $c->index(['tenant_id', 'status', 'created_at']));
        Schema::create('receipt_cancellations', fn (Blueprint $c) => $c->index(['tenant_id', 'status', 'created_at']));
        Schema::create('cash_closings', function (Blueprint $c): void {
            $c->index(['tenant_id', 'status', 'created_at']);
            $c->index(['tenant_id', 'collector_id', 'created_at']);
        });

        Schema::create('gateway_accounts', fn (Blueprint $c) => $c->index(['tenant_id'], 'tenant_unique', null, ['unique' => true]));
        // The webhook finds the school from (provider, order_id) inside the
        // documented bypass (decision 0015). The index still starts with
        // tenant_id (conventions); see the decision for what that costs.
        Schema::create('gateway_orders', function (Blueprint $c): void {
            $c->index(['tenant_id', 'provider', 'order_id'], 'tenant_provider_order_unique', null, ['unique' => true]);
            $c->index(['tenant_id', 'student_id', 'status']);
        });
        Schema::create('gateway_events', fn (Blueprint $c) => $c->index(['tenant_id', 'provider', 'gateway_payment_id', 'outcome'], 'tenant_payment_outcome_unique', null, ['unique' => true]));
        Schema::create('fee_reconciliations', fn (Blueprint $c) => $c->index(['tenant_id', 'date']));
    }

    public function down(): void
    {
        foreach (['fee_heads', 'fee_categories', 'fee_plans', 'student_fees', 'fee_demands', 'fee_ledger', 'payments', 'receipts', 'fee_counters', 'fee_adjustments',
            'receipt_cancellations', 'cash_closings', 'gateway_accounts', 'gateway_orders', 'gateway_events', 'fee_reconciliations'] as $collection) {
            Schema::dropIfExists($collection);
        }
    }
};
