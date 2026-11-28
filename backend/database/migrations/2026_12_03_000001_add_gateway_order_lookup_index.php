<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

/**
 * Decision 0015: the payment webhook finds the school from our own order
 * record, with a query that cannot carry tenant_id. This unique index on
 * (provider, order_id) lets that one query use an index instead of scanning
 * gateway_orders. It is the only school-collection index that does not
 * start with tenant_id (see docs/CONVENTIONS.md, "Database").
 */
return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        Schema::table('gateway_orders', fn (Blueprint $c) => $c->index(['provider', 'order_id'], 'provider_order_unique', null, ['unique' => true]));
    }

    public function down(): void
    {
        Schema::table('gateway_orders', fn (Blueprint $c) => $c->dropIndex('provider_order_unique'));
    }
};
