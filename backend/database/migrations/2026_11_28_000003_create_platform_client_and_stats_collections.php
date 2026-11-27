<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

/**
 * Platform console additions: client records, payments, per-school numbers
 * and alerts. Platform level (no tenant_id), listed in
 * config('tenancy.platform_collections').
 */
return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        Schema::create('school_clients', fn (Blueprint $c) => $c->unique('school_id'));
        Schema::create('school_payments', fn (Blueprint $c) => $c->index(['school_id', 'paid_on']));
        Schema::create('school_stats', fn (Blueprint $c) => $c->unique('school_id'));
        Schema::create('platform_alerts', function (Blueprint $c): void {
            $c->index(['school_id', 'metric', 'raised_at']);
            $c->index(['acknowledged_at', 'raised_at']);
        });
    }

    public function down(): void
    {
        foreach (['platform_alerts', 'school_stats', 'school_payments', 'school_clients'] as $collection) {
            Schema::dropIfExists($collection);
        }
    }
};
