<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

/**
 * School collections added in Phase 1 part B: roles, device sessions,
 * password links and the audit log. Every index starts with tenant_id.
 */
return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        Schema::create('roles', function (Blueprint $c): void {
            $c->index(['tenant_id', 'name'], 'tenant_name_unique', null, ['unique' => true]);
        });

        Schema::create('device_sessions', function (Blueprint $c): void {
            $c->index(['tenant_id', 'user_id', 'revoked_at']);
            $c->index(['tenant_id', 'token_id']);
            $c->index(['tenant_id', 'session_hash']);
        });

        Schema::create('password_tokens', function (Blueprint $c): void {
            $c->index(['tenant_id', 'token_hash'], 'tenant_token_hash_unique', null, ['unique' => true]);
            $c->index(['tenant_id', 'user_id', 'used_at']);
        });

        Schema::create('audit_logs', function (Blueprint $c): void {
            $c->index(['tenant_id', 'entity', 'entity_id', 'at']);
            $c->index(['tenant_id', 'at']);
            $c->index(['tenant_id', 'category', 'at']);
            $c->index(['tenant_id', 'actor_id', 'at']);
            $c->index(['tenant_id', 'support_grant_id', 'at']);
        });

        Schema::table('support_grants', function (Blueprint $c): void {
            $c->index(['tenant_id', 'agent_id', 'expires_at']);
        });

        // Login names for staff without email or phone.
        Schema::table('users', function (Blueprint $c): void {
            $c->index(['tenant_id', 'username'], 'tenant_username_unique', null, [
                'unique' => true,
                'partialFilterExpression' => ['username' => ['$type' => 'string']],
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $c): void {
            $c->dropIndex('tenant_username_unique');
        });

        Schema::table('support_grants', function (Blueprint $c): void {
            $c->dropIndex(['tenant_id', 'agent_id', 'expires_at']);
        });

        foreach (['audit_logs', 'password_tokens', 'device_sessions', 'roles'] as $collection) {
            Schema::dropIfExists($collection);
        }
    }
};
