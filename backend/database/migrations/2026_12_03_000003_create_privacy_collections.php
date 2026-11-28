<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

/**
 * Phase 9: DPDP features. Every index starts with tenant_id.
 */
return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        Schema::create('privacy_notices', fn (Blueprint $c) => $c->index(['tenant_id', 'status', 'version']));
        Schema::create('data_requests', function (Blueprint $c): void {
            $c->index(['tenant_id', 'status', 'due_on']);
            $c->index(['tenant_id', 'requester_id', 'created_at']);
        });
        Schema::create('retention_runs', fn (Blueprint $c) => $c->index(['tenant_id', 'kind', 'status']));
        Schema::create('privacy_incidents', fn (Blueprint $c) => $c->index(['tenant_id', 'detected_at']));
        Schema::table('consents', fn (Blueprint $c) => $c->index(['tenant_id', 'student_id', 'purpose', 'method', 'created_at']));
    }

    public function down(): void
    {
        foreach (['privacy_notices', 'data_requests', 'retention_runs', 'privacy_incidents'] as $collection) {
            Schema::dropIfExists($collection);
        }
    }
};
