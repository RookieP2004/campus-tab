<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

/**
 * Platform-level collections (not school data): tenants, licences,
 * platform admins, and Sanctum's tokens (looked up before the school is
 * known; decision 0005).
 */
return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $c): void {
            $c->unique('code');
            $c->index('group_id');
        });

        Schema::create('licences', function (Blueprint $c): void {
            $c->unique('key_hash');
            $c->index(['tenant_id', 'status']);
        });

        Schema::create('platform_admins', function (Blueprint $c): void {
            $c->unique('email');
        });

        Schema::create('personal_access_tokens', function (Blueprint $c): void {
            $c->unique('token');
            $c->index(['tokenable_type', 'tokenable_id']);
            $c->index('expires_at');
        });
    }

    public function down(): void
    {
        foreach (['personal_access_tokens', 'platform_admins', 'licences', 'tenants'] as $collection) {
            Schema::dropIfExists($collection);
        }
    }
};
