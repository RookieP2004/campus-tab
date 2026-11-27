<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

/**
 * Phase 1 part C: the platform console. Platform level, so no tenant_id
 * (listed in config('tenancy.platform_collections')).
 */
return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        Schema::create('platform_sessions', function (Blueprint $c): void {
            $c->unique('session_hash');
            $c->index(['admin_id', 'revoked_at']);
        });

        Schema::create('platform_audit_logs', function (Blueprint $c): void {
            $c->index('at');
            $c->index(['school_id', 'at']);
            $c->index(['actor_id', 'at']);
        });
    }

    public function down(): void
    {
        foreach (['platform_audit_logs', 'platform_sessions'] as $collection) {
            Schema::dropIfExists($collection);
        }
    }
};
